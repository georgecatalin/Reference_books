The difference between a Python developer who writes loops and one who writes Pythonic code comes down to this: knowing when data should be built in memory versus streamed lazily. In IoT work this isn't style — it's the difference between a process that handles 10k sensor records cleanly and one that OOMs on a Raspberry Pi.

---

### List comprehensions — concise in-memory construction

The pattern: `[expression for item in iterable if condition]`

python

```python
# C-style loop
temps = []
for raw in raw_readings:
    if raw > 0:
        temps.append(raw * 1.8 + 32)

# Comprehension — same thing, one line, faster (CPython optimizes it)
temps = [raw * 1.8 + 32 for raw in raw_readings if raw > 0]
```

The `if` clause is a filter — it runs before the expression. Both the filter and expression can be any valid Python expression.

python

```python
# Nested comprehension — flatten a list of lists
all_values = [v for device_readings in all_devices for v in device_readings]

# With method calls
topics = [t.strip().lower() for t in raw_topics if t.strip()]

# Conditional expression (ternary) inside comprehension
labels = ["alarm" if v > 50 else "normal" for v in readings]
```

#### Dict and set comprehensions — same syntax, different brackets

python

```python
# Dict comprehension — build a lookup table
device_map = {d.device_id: d for d in device_list}

# Invert a dict
inverted = {v: k for k, v in original.items()}

# Set comprehension — unique device IDs from a mixed list
seen_ids = {msg["device_id"] for msg in messages}
```

---

### The problem with comprehensions at scale

A list comprehension builds the entire result in memory before you can use any of it. For 100 records — fine. For 1 million sensor readings from a log file — you've just allocated a huge list to iterate through it once and throw it away.

python

```python
# This loads the ENTIRE file into memory as a list
temps = [float(line.split(",")[2]) for line in open("readings.csv")]
average = sum(temps) / len(temps)   # then iterates it again
```

On a device with 256MB RAM processing a multi-gigabyte log, this crashes. The fix is generators.

---

### Generator expressions — lazy evaluation

Change `[]` to `()` and you get a generator — an object that produces values one at a time on demand, never holding more than one in memory:

python

```python
# Generator expression — nothing is computed yet
temps = (float(line.split(",")[2]) for line in open("readings.csv"))

# Values are produced only as sum() consumes them
average = sum(temps) / ...   # but now temps is exhausted — can't reuse it
```

One critical property: **generators are single-use**. Once exhausted, they're empty. If you need to iterate twice, either use a list or recreate the generator.

---

### Generator functions — `yield`

When the logic is too complex for a one-liner, use a generator function:

python

```python
def valid_readings(filepath: str, min_val: float, max_val: float):
    """Stream validated readings from a CSV, one at a time."""
    with open(filepath) as f:
        next(f)   # skip header
        for line in f:
            parts = line.strip().split(",")
            if len(parts) < 3:
                continue
            try:
                value = float(parts[2])
                if min_val <= value <= max_val:
                    yield value          # suspend here, resume on next()
            except ValueError:
                continue
```

`yield` is a suspension point. When the caller asks for the next value, execution resumes from exactly where it paused. The function's local state — `filepath`, `f`, `line`, `parts` — is preserved between calls.

From the caller's side, it looks like any other iterable:

python

```python
for temp in valid_readings("sensors.csv", -40.0, 125.0):
    process(temp)   # only one float in memory at a time

# Or feed it into any function that accepts an iterable
average = sum(valid_readings("sensors.csv", -40.0, 125.0)) / count
```

---

### Generator chaining — building pipelines

This is where generators become powerful. Chain them like Unix pipes — each stage processes one item at a time, the whole pipeline uses O(1) memory regardless of input size:

python

```python
import csv
from typing import Iterator

def read_lines(filepath: str) -> Iterator[str]:
    with open(filepath) as f:
        yield from f          # yield from delegates to another iterable

def parse_csv(lines: Iterator[str]) -> Iterator[dict]:
    reader = csv.DictReader(lines)
    for row in reader:
        yield row

def filter_device(rows: Iterator[dict], device_id: str) -> Iterator[dict]:
    for row in rows:
        if row["device_id"] == device_id:
            yield row

def extract_value(rows: Iterator[dict], field: str) -> Iterator[float]:
    for row in rows:
        try:
            yield float(row[field])
        except (ValueError, KeyError):
            continue

# Pipeline — each stage is lazy, entire file never in memory
lines   = read_lines("telemetry.csv")
rows    = parse_csv(lines)
filtered = filter_device(rows, "sensor_01")
values  = extract_value(filtered, "temperature")

average = sum(values) / ...  # streams through all stages one row at a time
```

`yield from` delegates iteration to another iterable — it's cleaner than `for item in iterable: yield item`.

---

### `itertools` — the generator toolkit

python

```python
import itertools

# islice — take first N from any iterable (works on infinite generators too)
first_100 = list(itertools.islice(valid_readings("big.csv", -40, 125), 100))

# chain — concatenate multiple iterables without building a combined list
all_readings = itertools.chain(readings_jan, readings_feb, readings_mar)

# groupby — group consecutive items (input must be sorted by key)
from operator import itemgetter
sorted_msgs = sorted(messages, key=itemgetter("device_id"))
for device_id, group in itertools.groupby(sorted_msgs, key=itemgetter("device_id")):
    device_msgs = list(group)
    print(f"{device_id}: {len(device_msgs)} messages")

# takewhile / dropwhile — conditional slicing
warm_readings = list(itertools.takewhile(lambda x: x < 50.0, readings))

# batched (Python 3.12+) — process in chunks
for batch in itertools.batched(all_readings, 100):
    insert_to_db(batch)
```

---

### When to use what

|Situation|Tool|
|---|---|
|Build a small list to use multiple times|List comprehension|
|One-pass processing of large/unknown data|Generator expression|
|Complex multi-step streaming logic|Generator function with `yield`|
|Combine multiple iterables|`itertools.chain`|
|Process in fixed-size batches|`itertools.batched` / `islice`|
|Group by key|`itertools.groupby`|

---

### Today's deliverable

python

```python
# sensor_pipeline.py
import csv
import itertools
from typing import Iterator
from collections import defaultdict

# --- Generator pipeline stages ---

def read_csv_rows(filepath: str) -> Iterator[dict]:
    """Lazily yield rows from a CSV file as dicts."""
    with open(filepath, newline="") as f:
        reader = csv.DictReader(f)
        for row in reader:
            yield row

def parse_reading(rows: Iterator[dict]) -> Iterator[tuple[str, str, float, float]]:
    """Parse and validate rows into (device_id, variable, value, timestamp) tuples."""
    for row in rows:
        try:
            yield (
                row["device_id"],
                row["variable"],
                float(row["value"]),
                float(row["timestamp"]),
            )
        except (ValueError, KeyError):
            continue

def filter_variable(
    readings: Iterator[tuple[str, str, float, float]],
    variable: str,
) -> Iterator[tuple[str, str, float, float]]:
    return (r for r in readings if r[1] == variable)

def filter_range(
    readings: Iterator[tuple[str, str, float, float]],
    low: float,
    high: float,
) -> Iterator[tuple[str, str, float, float]]:
    return (r for r in readings if low <= r[2] <= high)


# --- Generate a test CSV in memory ---
import io

def make_test_csv() -> str:
    lines = ["device_id,variable,value,timestamp"]
    import time, random, math
    random.seed(42)
    base_ts = 1700000000.0
    for i in range(200):
        dev = f"sensor_{(i % 3) + 1:02d}"
        var = "temperature" if i % 2 == 0 else "humidity"
        val = round(20 + 10 * math.sin(i / 10) + random.uniform(-1, 1), 2)
        lines.append(f"{dev},{var},{val},{base_ts + i}")
    return "\n".join(lines)


# --- Main: run the pipeline ---
if __name__ == "__main__":
    # Write test data to a temp file
    import tempfile, os
    with tempfile.NamedTemporaryFile(mode="w", suffix=".csv", delete=False) as f:
        f.write(make_test_csv())
        tmpfile = f.name

    try:
        # Pipeline
        rows     = read_csv_rows(tmpfile)
        readings = parse_reading(rows)
        temps    = filter_variable(readings, "temperature")
        in_range = filter_range(temps, 15.0, 35.0)

        # Consume — group by device using comprehension
        by_device: dict[str, list[float]] = defaultdict(list)
        for device_id, variable, value, ts in in_range:
            by_device[device_id].append(value)

        for dev, values in sorted(by_device.items()):
            avg = sum(values) / len(values)
            print(f"{dev}: {len(values)} readings, avg={avg:.2f}, "
                  f"min={min(values):.2f}, max={max(values):.2f}")

        # Bonus: use itertools.islice to peek at first 5 raw rows
        # without consuming the whole file
        print("\nFirst 5 rows (islice):")
        for row in itertools.islice(read_csv_rows(tmpfile), 5):
            print(" ", row)

    finally:
        os.unlink(tmpfile)
```
