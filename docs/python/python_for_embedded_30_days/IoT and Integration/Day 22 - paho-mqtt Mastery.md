You've used paho-mqtt before. Today goes deep on the parts that matter in production: QoS semantics, robust reconnection, callback architecture, and the threading model inside the library itself.

---

### The paho-mqtt threading model — know this first

paho-mqtt has three loop modes. Choosing the wrong one causes subtle bugs:

python

```python
# Mode 1: loop_forever() — blocks the calling thread, handles reconnect internally
client.loop_forever()   # never returns unless disconnect() called

# Mode 2: loop_start() — spawns a background thread, non-blocking
client.loop_start()     # returns immediately, network handled in background
# ... your code runs here ...
client.loop_stop()      # stop the background thread

# Mode 3: loop() — manual, call it yourself in a loop
while True:
    client.loop(timeout=1.0)   # process pending network events
```

For a daemon/service: `loop_start()` is the right choice — it runs the network loop in a daemon thread while your main thread handles business logic. `loop_forever()` is correct only when the MQTT loop IS your main loop.

---

### QoS — the real trade-offs

```
QoS 0 — fire and forget
  Publisher → Broker: PUBLISH
  No acknowledgment. Message may be lost if broker is down.
  Use for: high-frequency telemetry where losing one reading is acceptable.

QoS 1 — at least once
  Publisher → Broker: PUBLISH
  Broker → Publisher: PUBACK
  Message delivered at least once. May be duplicated if PUBACK lost in transit.
  Use for: commands, alerts, anything where loss is unacceptable but duplicates are handled.

QoS 2 — exactly once
  4-way handshake: PUBLISH → PUBREC → PUBREL → PUBCOMP
  Guaranteed exactly once delivery. Highest overhead.
  Use for: financial transactions, OTA triggers — anywhere duplicates cause real harm.
```

For your device fleet: telemetry at QoS 0, OTA commands at QoS 2, device status at QoS 1. Mismatched QoS is a common production bug — the effective QoS is `min(publisher QoS, subscriber QoS)`.

---

### The minimal robust client

python

```python
import paho.mqtt.client as mqtt
import time
import threading
import logging

logger = logging.getLogger(__name__)

class RobustMQTTClient:
    def __init__(
        self,
        host:        str,
        port:        int   = 1883,
        client_id:   str   = "",
        keepalive:   int   = 60,
    ) -> None:
        self._host      = host
        self._port      = port
        self._keepalive = keepalive

        self._client = mqtt.Client(
            client_id=client_id,
            clean_session=True,
            protocol=mqtt.MQTTv311,
        )
        self._client.on_connect    = self._on_connect
        self._client.on_disconnect = self._on_disconnect
        self._client.on_message    = self._on_message

        self._connected     = threading.Event()
        self._subscriptions: dict[str, int] = {}   # topic → qos

    # --- Callbacks (called from paho's network thread) ---

    def _on_connect(self, client, userdata, flags, rc) -> None:
        if rc == 0:
            logger.info("Connected to %s:%d", self._host, self._port)
            self._connected.set()
            # Re-subscribe after reconnect — critical
            for topic, qos in self._subscriptions.items():
                client.subscribe(topic, qos)
        else:
            logger.error("Connect failed: rc=%d (%s)", rc, mqtt.connack_string(rc))

    def _on_disconnect(self, client, userdata, rc) -> None:
        self._connected.clear()
        if rc != 0:
            logger.warning("Unexpected disconnect: rc=%d", rc)
        # paho handles reconnect automatically when loop_start() is running

    def _on_message(self, client, userdata, message: mqtt.MQTTMessage) -> None:
        # Called from paho's thread — keep this fast, don't block
        self._handle_message(message.topic, message.payload)

    def _handle_message(self, topic: str, payload: bytes) -> None:
        logger.debug("Message: %s = %r", topic, payload)

    # --- Public API ---

    def connect(self) -> bool:
        self._client.connect(self._host, self._port, self._keepalive)
        self._client.loop_start()
        return self._connected.wait(timeout=10.0)

    def disconnect(self) -> None:
        self._client.disconnect()
        self._client.loop_stop()
        self._connected.clear()

    def subscribe(self, topic: str, qos: int = 0) -> None:
        self._subscriptions[topic] = qos
        if self._connected.is_set():
            self._client.subscribe(topic, qos)

    def publish(self, topic: str, payload: bytes, qos: int = 0, retain: bool = False) -> None:
        if not self._connected.is_set():
            raise RuntimeError("Not connected")
        result = self._client.publish(topic, payload, qos=qos, retain=retain)
        result.wait_for_publish(timeout=5.0)   # blocks until broker acks (QoS 1/2)

    def wait_for_connection(self, timeout: float = 10.0) -> bool:
        return self._connected.wait(timeout=timeout)

    def __enter__(self):
        self.connect()
        return self

    def __exit__(self, *args):
        self.disconnect()
```

---

### Reconnect with exponential backoff

paho has built-in reconnect, but it doesn't give you control over the backoff strategy. For production, manage reconnection yourself:

python

```python
import random

class BackoffReconnectClient(RobustMQTTClient):
    def __init__(self, *args, **kwargs) -> None:
        super().__init__(*args, **kwargs)
        self._reconnect_delay    = 1.0
        self._reconnect_delay_max = 120.0
        self._reconnect_jitter   = 0.3

    def _on_disconnect(self, client, userdata, rc) -> None:
        super()._on_disconnect(client, userdata, rc)
        if rc != 0:
            self._schedule_reconnect()

    def _schedule_reconnect(self) -> None:
        jitter = random.uniform(0, self._reconnect_jitter * self._reconnect_delay)
        delay  = min(self._reconnect_delay + jitter, self._reconnect_delay_max)
        logger.info("Reconnecting in %.1fs", delay)
        threading.Timer(delay, self._attempt_reconnect).start()
        # Exponential backoff
        self._reconnect_delay = min(self._reconnect_delay * 2, self._reconnect_delay_max)

    def _attempt_reconnect(self) -> None:
        try:
            self._client.reconnect()
            self._reconnect_delay = 1.0   # reset on success
        except Exception as e:
            logger.warning("Reconnect failed: %s", e)
            self._schedule_reconnect()
```

Jitter is important — without it, a fleet of 500 devices all disconnect simultaneously (power blip) and all reconnect at the same time, overwhelming the broker. Jitter spreads the reconnections out.

---

### Message queuing during disconnect

During a disconnect window, messages should be buffered, not dropped:

python

```python
import queue

class BufferedMQTTClient(RobustMQTTClient):
    def __init__(self, *args, max_buffer: int = 1000, **kwargs) -> None:
        super().__init__(*args, **kwargs)
        self._send_buffer: queue.Queue = queue.Queue(maxsize=max_buffer)
        self._flush_thread = threading.Thread(target=self._flush_loop, daemon=True)

    def connect(self) -> bool:
        result = super().connect()
        self._flush_thread.start()
        return result

    def publish(self, topic: str, payload: bytes, qos: int = 0, retain: bool = False) -> None:
        try:
            self._send_buffer.put_nowait((topic, payload, qos, retain))
        except queue.Full:
            logger.warning("Send buffer full — dropping message")

    def _flush_loop(self) -> None:
        while True:
            topic, payload, qos, retain = self._send_buffer.get()
            self._connected.wait()   # block until connected
            try:
                result = self._client.publish(topic, payload, qos=qos, retain=retain)
                if qos > 0:
                    result.wait_for_publish(timeout=5.0)
            except Exception as e:
                logger.error("Publish failed: %s — requeueing", e)
                self._send_buffer.put((topic, payload, qos, retain))
```

---

### Will messages — last testament of a device

A will message is published by the broker automatically when a client disconnects unexpectedly (no clean disconnect). Use it to notify your system that a device went offline:

python

```python
client = mqtt.Client(client_id="sensor_01")
client.will_set(
    topic   = "devices/sensor_01/status",
    payload = b'{"online": false, "reason": "unexpected_disconnect"}',
    qos     = 1,
    retain  = True,   # retain so new subscribers see the last known status
)
client.connect(host, port)
```

On clean connect, publish an online status with `retain=True`:

python

```python
client.publish(
    "devices/sensor_01/status",
    b'{"online": true}',
    qos=1,
    retain=True,
)
```

This gives you device presence tracking with zero application logic on the broker side.

---

### Topic design for a device fleet

Good topic structure makes routing, filtering, and access control tractable:

```
devices/{device_id}/telemetry/{variable}    — sensor readings
devices/{device_id}/status                  — online/offline (retained)
devices/{device_id}/command                 — incoming commands
devices/{device_id}/command/ack             — command acknowledgment
devices/{device_id}/ota/request             — OTA trigger
devices/{device_id}/ota/progress            — OTA progress updates
fleet/broadcast                             — command to all devices
```

Wildcard subscriptions:

python

```python
client.subscribe("devices/+/telemetry/#", qos=0)   # all telemetry from all devices
client.subscribe("devices/sensor_01/#", qos=1)     # everything from one device
client.subscribe("fleet/#", qos=2)                 # all fleet-wide messages
```

`+` matches one level, `#` matches remaining levels. Topic hierarchy design is architecture — get it right before deploying a fleet.

---

### Today's deliverable

python

```python
# robust_mqtt_client.py
import paho.mqtt.client as mqtt
import threading
import queue
import json
import time
import random
import logging
from typing import Callable, Optional
from dataclasses import dataclass, field

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)-8s %(name)s: %(message)s",
    datefmt="%H:%M:%S",
)
logger = logging.getLogger("mqtt_client")


@dataclass
class MQTTConfig:
    host:              str
    port:              int   = 1883
    client_id:         str   = ""
    keepalive:         int   = 60
    max_buffer:        int   = 500
    reconnect_min:     float = 1.0
    reconnect_max:     float = 60.0
    reconnect_jitter:  float = 0.25


MessageCallback = Callable[[str, bytes], None]


class ProductionMQTTClient:
    def __init__(self, config: MQTTConfig) -> None:
        self._cfg    = config
        self._client = mqtt.Client(
            client_id=config.client_id or f"client_{random.randint(1000,9999)}",
            clean_session=True,
            protocol=mqtt.MQTTv311,
        )
        self._client.on_connect    = self._on_connect
        self._client.on_disconnect = self._on_disconnect
        self._client.on_message    = self._on_message

        self._connected      = threading.Event()
        self._stop           = threading.Event()
        self._subscriptions: dict[str, tuple[int, MessageCallback]] = {}
        self._send_queue: queue.Queue = queue.Queue(maxsize=config.max_buffer)
        self._reconnect_delay = config.reconnect_min

        self._stats = {
            "received":    0,
            "sent":        0,
            "dropped":     0,
            "reconnects":  0,
        }

    # --- paho callbacks ---

    def _on_connect(self, client, userdata, flags, rc) -> None:
        if rc == 0:
            self._connected.set()
            self._reconnect_delay = self._cfg.reconnect_min
            logger.info("Connected to %s:%d", self._cfg.host, self._cfg.port)
            for topic, (qos, _) in self._subscriptions.items():
                client.subscribe(topic, qos)
                logger.debug("Re-subscribed: %s (QoS %d)", topic, qos)
        else:
            logger.error("Connection refused: %s", mqtt.connack_string(rc))

    def _on_disconnect(self, client, userdata, rc) -> None:
        self._connected.clear()
        if rc != 0:
            logger.warning("Unexpected disconnect (rc=%d) — will reconnect", rc)
            self._stats["reconnects"] += 1
            self._schedule_reconnect()

    def _on_message(self, client, userdata, message: mqtt.MQTTMessage) -> None:
        self._stats["received"] += 1
        topic   = message.topic
        payload = message.payload
        _, callback = self._subscriptions.get(topic, (0, None))
        if callback is None:
            for pattern, (_, cb) in self._subscriptions.items():
                if self._topic_matches(pattern, topic) and cb:
                    cb(topic, payload)
                    return
        elif callback:
            callback(topic, payload)

    @staticmethod
    def _topic_matches(pattern: str, topic: str) -> bool:
        p_parts = pattern.split("/")
        t_parts = topic.split("/")
        for i, p in enumerate(p_parts):
            if p == "#":
                return True
            if i >= len(t_parts):
                return False
            if p != "+" and p != t_parts[i]:
                return False
        return len(p_parts) == len(t_parts)

    # --- Reconnect ---

    def _schedule_reconnect(self) -> None:
        jitter = random.uniform(0, self._cfg.reconnect_jitter * self._reconnect_delay)
        delay  = min(self._reconnect_delay + jitter, self._cfg.reconnect_max)
        self._reconnect_delay = min(self._reconnect_delay * 2, self._cfg.reconnect_max)
        logger.info("Reconnecting in %.1fs", delay)
        threading.Timer(delay, self._attempt_reconnect).start()

    def _attempt_reconnect(self) -> None:
        if self._stop.is_set():
            return
        try:
            self._client.reconnect()
        except Exception as e:
            logger.warning("Reconnect attempt failed: %s", e)
            self._schedule_reconnect()

    # --- Flush loop: drain send queue when connected ---

    def _flush_loop(self) -> None:
        while not self._stop.is_set():
            try:
                item = self._send_queue.get(timeout=0.2)
            except queue.Empty:
                continue
            self._connected.wait()
            topic, payload, qos, retain = item
            try:
                info = self._client.publish(topic, payload, qos=qos, retain=retain)
                if qos > 0:
                    info.wait_for_publish(timeout=5.0)
                self._stats["sent"] += 1
            except Exception as e:
                logger.error("Publish error: %s", e)
                try:
                    self._send_queue.put_nowait(item)
                except queue.Full:
                    self._stats["dropped"] += 1

    # --- Public API ---

    def connect(self) -> bool:
        self._client.will_set(
            f"devices/{self._cfg.client_id}/status",
            json.dumps({"online": False, "reason": "unexpected"}).encode(),
            qos=1,
            retain=True,
        )
        self._client.connect(self._cfg.host, self._cfg.port, self._cfg.keepalive)
        self._client.loop_start()
        threading.Thread(target=self._flush_loop, daemon=True).start()
        connected = self._connected.wait(timeout=10.0)
        if connected:
            self._client.publish(
                f"devices/{self._cfg.client_id}/status",
                json.dumps({"online": True}).encode(),
                qos=1,
                retain=True,
            )
        return connected

    def disconnect(self) -> None:
        self._stop.set()
        self._client.disconnect()
        self._client.loop_stop()

    def subscribe(self, topic: str, callback: MessageCallback, qos: int = 0) -> None:
        self._subscriptions[topic] = (qos, callback)
        if self._connected.is_set():
            self._client.subscribe(topic, qos)

    def publish(self, topic: str, payload: bytes, qos: int = 0, retain: bool = False) -> None:
        try:
            self._send_queue.put_nowait((topic, payload, qos, retain))
        except queue.Full:
            self._stats["dropped"] += 1
            logger.warning("Send buffer full — message dropped")

    def stats(self) -> dict:
        return dict(self._stats)

    def __enter__(self):
        self.connect()
        return self

    def __exit__(self, *args):
        self.disconnect()


# --- Demo: requires a running MQTT broker (mosquitto on localhost:1883) ---
# To test without a broker, use the mock below

class MockBroker:
    """In-process mock broker for testing without mosquitto."""
    def __init__(self) -> None:
        self._subscribers: dict[str, list[Callable]] = {}
        self._lock = threading.Lock()

    def subscribe(self, topic: str, cb: Callable) -> None:
        with self._lock:
            self._subscribers.setdefault(topic, []).append(cb)

    def publish(self, topic: str, payload: bytes) -> None:
        with self._lock:
            for pattern, cbs in self._subscribers.items():
                if ProductionMQTTClient._topic_matches(pattern, topic):
                    for cb in cbs:
                        cb(topic, payload)


if __name__ == "__main__":
    # Self-contained demo using mock broker
    broker = MockBroker()
    received: list[tuple] = []

    def on_telemetry(topic: str, payload: bytes) -> None:
        data = json.loads(payload)
        received.append((topic, data["value"]))
        logger.info("Received  %s = %s", topic, data["value"])

    broker.subscribe("devices/+/telemetry/#", on_telemetry)

    # Simulate 10 publishes
    random.seed(42)
    for i in range(10):
        device = f"dev_{i % 3:02d}"
        value  = round(20 + random.gauss(0, 3), 2)
        topic  = f"devices/{device}/telemetry/temperature"
        broker.publish(topic, json.dumps({"value": value}).encode())
        time.sleep(0.05)

    print(f"\nReceived {len(received)} messages via mock broker")
    for topic, val in received:
        print(f"  {topic}: {val}")

    print("\nTo test with a real broker:")
    print("  docker run -p 1883:1883 eclipse-mosquitto")
    print("  Then instantiate ProductionMQTTClient with MQTTConfig(host='localhost')")
```

Run this, verify the mock broker routes messages correctly, then connect it to a real mosquitto instance if you have one available. The `_topic_matches` method is the part worth studying — wildcard MQTT routing in 10 lines.

---
[[IoT and Integration]]