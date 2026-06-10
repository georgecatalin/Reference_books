##### Weather CLI with API, Async, and SQLite

Week 3 project. Everything from this week in one application: real API calls, async concurrency, SQLite persistence, and tests.

---

## What you're building

A command-line weather tool that:

- Fetches current weather for one or multiple cities concurrently
- Stores every lookup in a SQLite database
- Lets you query your search history
- Has a full test suite with mocks

---

## Project structure

```
weather_cli/
├── weather_cli/
│   ├── __init__.py
│   ├── api.py          # async API client
│   ├── database.py     # SQLite storage
│   ├── models.py       # data structures
│   └── cli.py          # command-line interface
├── tests/
│   ├── __init__.py
│   ├── test_api.py
│   ├── test_database.py
│   └── test_cli.py
├── .env
├── requirements.txt
└── main.py
```

```bash
mkdir -p weather_cli/weather_cli weather_cli/tests
cd weather_cli
touch weather_cli/__init__.py weather_cli/api.py weather_cli/database.py
touch weather_cli/models.py weather_cli/cli.py
touch tests/__init__.py tests/test_api.py tests/test_database.py tests/test_cli.py
touch main.py .env requirements.txt
```

---

## API setup — OpenWeatherMap

Sign up at openweathermap.org — the free tier gives you 60 calls/minute, more than enough.

```
# .env
OPENWEATHER_API_KEY=your_key_here
```

```
# requirements.txt
aiohttp==3.9.1
python-dotenv==1.0.0
pytest==7.4.0
pytest-asyncio==0.23.2
```

```bash
pip install -r requirements.txt
```

---

## models.py — data structures

```python
# weather_cli/models.py

from dataclasses import dataclass
from datetime import datetime
from typing import Optional


@dataclass
class WeatherData:
    city: str
    country: str
    temperature_c: float
    feels_like_c: float
    humidity: int
    description: str
    wind_speed: float
    fetched_at: datetime

    @property
    def temperature_f(self):
        return round(self.temperature_c * 9/5 + 32, 1)

    @property
    def feels_like_f(self):
        return round(self.feels_like_c * 9/5 + 32, 1)

    def format_display(self, unit="c"):
        temp = self.temperature_c if unit == "c" else self.temperature_f
        feels = self.feels_like_c if unit == "c" else self.feels_like_f
        symbol = "°C" if unit == "c" else "°F"
        return (
            f"\n  {self.city}, {self.country}\n"
            f"  {self.description.title()}\n"
            f"  Temperature:  {temp:.1f}{symbol} "
            f"(feels like {feels:.1f}{symbol})\n"
            f"  Humidity:     {self.humidity}%\n"
            f"  Wind:         {self.wind_speed} m/s\n"
            f"  Fetched:      {self.fetched_at.strftime('%Y-%m-%d %H:%M:%S')}\n"
        )

    def to_dict(self):
        return {
            "city": self.city,
            "country": self.country,
            "temperature_c": self.temperature_c,
            "feels_like_c": self.feels_like_c,
            "humidity": self.humidity,
            "description": self.description,
            "wind_speed": self.wind_speed,
            "fetched_at": self.fetched_at.isoformat(),
        }

    @classmethod
    def from_api_response(cls, data):
        """Parse the OpenWeatherMap API response."""
        return cls(
            city=data["name"],
            country=data["sys"]["country"],
            temperature_c=round(data["main"]["temp"] - 273.15, 1),
            feels_like_c=round(data["main"]["feels_like"] - 273.15, 1),
            humidity=data["main"]["humidity"],
            description=data["weather"][0]["description"],
            wind_speed=data["wind"]["speed"],
            fetched_at=datetime.now(),
        )


@dataclass
class HistoryRecord:
    id: int
    city: str
    country: str
    temperature_c: float
    description: str
    fetched_at: str

    def __str__(self):
        return (
            f"  [{self.id:>4}] {self.fetched_at[:16]}  "
            f"{self.city}, {self.country:<4}  "
            f"{self.temperature_c:>6.1f}°C  "
            f"{self.description}"
        )
```

---

## api.py — async weather client

```python
# weather_cli/api.py

import asyncio
import aiohttp
from typing import Optional
from .models import WeatherData


class WeatherAPIError(Exception):
    """Raised when the weather API returns an error."""
    def __init__(self, city, status, message):
        self.city = city
        self.status = status
        super().__init__(f"Weather API error for {city!r}: [{status}] {message}")


class WeatherClient:
    """Async client for the OpenWeatherMap API."""

    BASE_URL = "https://api.openweathermap.org/data/2.5/weather"

    def __init__(self, api_key, max_concurrent=5):
        self.api_key = api_key
        self._semaphore = asyncio.Semaphore(max_concurrent)

    async def fetch_one(self, session, city):
        """
        Fetch weather for a single city.
        Returns WeatherData or raises WeatherAPIError.
        """
        params = {
            "q": city,
            "appid": self.api_key,
        }

        async with self._semaphore:
            try:
                async with session.get(
                    self.BASE_URL,
                    params=params,
                    timeout=aiohttp.ClientTimeout(total=10)
                ) as response:

                    data = await response.json()

                    if response.status == 200:
                        return WeatherData.from_api_response(data)
                    elif response.status == 404:
                        raise WeatherAPIError(city, 404, "City not found")
                    elif response.status == 401:
                        raise WeatherAPIError(city, 401, "Invalid API key")
                    elif response.status == 429:
                        raise WeatherAPIError(city, 429, "Rate limit exceeded")
                    else:
                        msg = data.get("message", "Unknown error")
                        raise WeatherAPIError(city, response.status, msg)

            except asyncio.TimeoutError:
                raise WeatherAPIError(city, 0, "Request timed out")
            except aiohttp.ClientConnectionError:
                raise WeatherAPIError(city, 0, "Connection failed")

    async def fetch_many(self, cities):
        """
        Fetch weather for multiple cities concurrently.
        Returns dict of {city: WeatherData or WeatherAPIError}
        """
        async with aiohttp.ClientSession() as session:
            tasks = {
                city: asyncio.create_task(self.fetch_one(session, city))
                for city in cities
            }

            results = {}
            for city, task in tasks.items():
                try:
                    results[city] = await task
                except WeatherAPIError as e:
                    results[city] = e

        return results

    def fetch(self, cities):
        """
        Synchronous entry point — runs the async code.
        Accepts a single city string or list of cities.
        """
        if isinstance(cities, str):
            cities = [cities]

        return asyncio.run(self.fetch_many(cities))
```

---

## database.py — SQLite history storage

```python
# weather_cli/database.py

import sqlite3
from pathlib import Path
from contextlib import contextmanager
from .models import WeatherData, HistoryRecord


SCHEMA = """
CREATE TABLE IF NOT EXISTS weather_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    city TEXT NOT NULL,
    country TEXT NOT NULL,
    temperature_c REAL NOT NULL,
    feels_like_c REAL NOT NULL,
    humidity INTEGER NOT NULL,
    description TEXT NOT NULL,
    wind_speed REAL NOT NULL,
    fetched_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_history_city
    ON weather_history(city COLLATE NOCASE);

CREATE INDEX IF NOT EXISTS idx_history_fetched
    ON weather_history(fetched_at);
"""


class WeatherDatabase:
    """Stores weather lookup history in SQLite."""

    def __init__(self, filepath="weather_history.db"):
        self.filepath = Path(filepath)
        self._init()

    def _init(self):
        with self._db() as conn:
            conn.executescript(SCHEMA)

    @contextmanager
    def _db(self):
        conn = sqlite3.connect(str(self.filepath))
        conn.row_factory = sqlite3.Row
        conn.execute("PRAGMA journal_mode=WAL")
        try:
            yield conn
            conn.commit()
        except Exception:
            conn.rollback()
            raise
        finally:
            conn.close()

    def save(self, weather):
        """Save a WeatherData record to history."""
        with self._db() as conn:
            conn.execute("""
                INSERT INTO weather_history
                    (city, country, temperature_c, feels_like_c,
                     humidity, description, wind_speed, fetched_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            """, (
                weather.city,
                weather.country,
                weather.temperature_c,
                weather.feels_like_c,
                weather.humidity,
                weather.description,
                weather.wind_speed,
                weather.fetched_at.isoformat(),
            ))

    def save_many(self, weather_list):
        """Save multiple WeatherData records efficiently."""
        with self._db() as conn:
            conn.executemany("""
                INSERT INTO weather_history
                    (city, country, temperature_c, feels_like_c,
                     humidity, description, wind_speed, fetched_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            """, [
                (w.city, w.country, w.temperature_c, w.feels_like_c,
                 w.humidity, w.description, w.wind_speed, w.fetched_at.isoformat())
                for w in weather_list
            ])

    def get_history(self, city=None, limit=20):
        """
        Get recent weather history.
        Optionally filter by city name (case-insensitive).
        """
        if city:
            query = """
                SELECT * FROM weather_history
                WHERE city LIKE ?
                ORDER BY fetched_at DESC
                LIMIT ?
            """
            params = (f"%{city}%", limit)
        else:
            query = """
                SELECT * FROM weather_history
                ORDER BY fetched_at DESC
                LIMIT ?
            """
            params = (limit,)

        with self._db() as conn:
            rows = conn.execute(query, params).fetchall()

        return [
            HistoryRecord(
                id=row["id"],
                city=row["city"],
                country=row["country"],
                temperature_c=row["temperature_c"],
                description=row["description"],
                fetched_at=row["fetched_at"],
            )
            for row in rows
        ]

    def get_stats(self, city):
        """Get temperature statistics for a city."""
        with self._db() as conn:
            row = conn.execute("""
                SELECT
                    city,
                    COUNT(*) as lookups,
                    ROUND(AVG(temperature_c), 1) as avg_temp,
                    ROUND(MIN(temperature_c), 1) as min_temp,
                    ROUND(MAX(temperature_c), 1) as max_temp,
                    MIN(fetched_at) as first_lookup,
                    MAX(fetched_at) as last_lookup
                FROM weather_history
                WHERE city LIKE ?
                GROUP BY city
            """, (city,)).fetchone()

        return dict(row) if row else None

    def clear_history(self, city=None):
        """Clear all history, or history for a specific city."""
        with self._db() as conn:
            if city:
                conn.execute(
                    "DELETE FROM weather_history WHERE city LIKE ?",
                    (f"%{city}%",)
                )
            else:
                conn.execute("DELETE FROM weather_history")
```

---

## cli.py — command-line interface

```python
# weather_cli/cli.py

import sys
from .api import WeatherClient, WeatherAPIError
from .database import WeatherDatabase


def cmd_weather(client, db, args):
    """Fetch weather for one or more cities."""
    if not args:
        print("  Usage: weather <city> [city2 city3 ...]")
        print("  Example: weather London 'New York' Tokyo")
        return

    unit = "f" if "--fahrenheit" in args or "-f" in args else "c"
    cities = [a for a in args if not a.startswith("-")]

    if not cities:
        print("  No cities specified.")
        return

    print(f"\n  Fetching weather for: {', '.join(cities)}...")

    results = client.fetch(cities)
    successful = []

    for city in cities:
        result = results.get(city)
        if isinstance(result, WeatherAPIError):
            print(f"\n  Error ({city}): {result}")
        else:
            print(result.format_display(unit))
            successful.append(result)

    if successful:
        db.save_many(successful)
        s = "s" if len(successful) > 1 else ""
        print(f"  Saved {len(successful)} record{s} to history.\n")


def cmd_history(db, args):
    """Show lookup history."""
    city = None
    limit = 20

    i = 0
    while i < len(args):
        if args[i] in ("--city", "-c") and i + 1 < len(args):
            city = args[i + 1]
            i += 2
        elif args[i] in ("--limit", "-n") and i + 1 < len(args):
            try:
                limit = int(args[i + 1])
            except ValueError:
                print(f"  Invalid limit: {args[i+1]!r}")
                return
            i += 2
        else:
            city = args[i]
            i += 1

    records = db.get_history(city=city, limit=limit)

    if not records:
        msg = f"for {city!r} " if city else ""
        print(f"\n  No history found {msg}.\n")
        return

    filter_msg = f" for {city!r}" if city else ""
    print(f"\n  Recent lookups{filter_msg} (last {len(records)}):\n")
    print(f"  {'ID':>5}  {'TIME':<16}  {'CITY':<20}  {'TEMP':>7}  DESCRIPTION")
    print("  " + "-" * 65)
    for record in records:
        print(record)
    print()


def cmd_stats(db, args):
    """Show statistics for a city."""
    if not args:
        print("  Usage: stats <city>")
        return

    city = " ".join(args)
    stats = db.get_stats(city)

    if not stats:
        print(f"\n  No data found for {city!r}.\n")
        return

    print(f"""
  Stats for {stats['city']}:
    Lookups:     {stats['lookups']}
    Avg temp:    {stats['avg_temp']}°C
    Min temp:    {stats['min_temp']}°C
    Max temp:    {stats['max_temp']}°C
    First seen:  {stats['first_lookup'][:16]}
    Last seen:   {stats['last_lookup'][:16]}
""")


def cmd_clear(db, args):
    """Clear history."""
    city = " ".join(args) if args else None

    if city:
        db.clear_history(city)
        print(f"\n  Cleared history for {city!r}.\n")
    else:
        confirm = input("  Clear ALL history? [y/N] ").strip().lower()
        if confirm == "y":
            db.clear_history()
            print("\n  History cleared.\n")
        else:
            print("\n  Cancelled.\n")


def show_help():
    print("""
  Weather CLI — Commands:

    weather <city> [city2 ...]   Fetch current weather
    weather <city> --fahrenheit  Show in Fahrenheit
    history                      Show recent lookups
    history <city>               Filter history by city
    history --limit 50           Show more results
    stats <city>                 Show statistics for a city
    clear                        Clear all history
    clear <city>                 Clear history for a city
    help                         Show this message
    quit                         Exit
""")


def run(api_key, db_path="weather_history.db"):
    """Main CLI loop."""
    client = WeatherClient(api_key)
    db = WeatherDatabase(db_path)

    print("\n  Weather CLI  —  type 'help' for commands")
    show_help()

    while True:
        try:
            raw = input("weather> ").strip()
            if not raw:
                continue

            parts = raw.split()
            command = parts[0].lower()
            args = parts[1:]

            if command == "weather":
                cmd_weather(client, db, args)
            elif command == "history":
                cmd_history(db, args)
            elif command == "stats":
                cmd_stats(db, args)
            elif command == "clear":
                cmd_clear(db, args)
            elif command == "help":
                show_help()
            elif command in ("quit", "exit", "q"):
                print("\n  Goodbye.\n")
                break
            else:
                print(f"\n  Unknown command {command!r}. Type 'help'.\n")

        except KeyboardInterrupt:
            print("\n\n  Goodbye.\n")
            break
        except Exception as e:
            print(f"\n  Unexpected error: {e}\n")
```

---

## main.py — entry point

```python
# main.py

import sys
import os
from dotenv import load_dotenv

def main():
    load_dotenv()

    api_key = os.environ.get("OPENWEATHER_API_KEY")
    if not api_key:
        print("Error: OPENWEATHER_API_KEY not set.")
        print("Add it to your .env file or set it as an environment variable.")
        sys.exit(1)

    from weather_cli.cli import run
    run(api_key)


if __name__ == "__main__":
    main()
```

---

## tests/test_api.py

```python
# tests/test_api.py

import pytest
import asyncio
from unittest.mock import AsyncMock, MagicMock, patch
from weather_cli.api import WeatherClient, WeatherAPIError
from weather_cli.models import WeatherData


SAMPLE_API_RESPONSE = {
    "name": "London",
    "sys": {"country": "GB"},
    "main": {
        "temp": 288.15,       # 15°C
        "feels_like": 286.15, # 13°C
        "humidity": 75,
    },
    "weather": [{"description": "light rain"}],
    "wind": {"speed": 5.5},
}


@pytest.fixture
def client():
    return WeatherClient(api_key="test_key_123")


def make_mock_response(status, json_data):
    """Helper to create a mock aiohttp response."""
    mock_resp = AsyncMock()
    mock_resp.status = status
    mock_resp.json = AsyncMock(return_value=json_data)
    mock_resp.__aenter__ = AsyncMock(return_value=mock_resp)
    mock_resp.__aexit__ = AsyncMock(return_value=None)
    return mock_resp


class TestFetchOne:

    @pytest.mark.asyncio
    async def test_successful_fetch_returns_weather_data(self, client):
        mock_resp = make_mock_response(200, SAMPLE_API_RESPONSE)

        with patch("aiohttp.ClientSession") as mock_session_cls:
            mock_session = AsyncMock()
            mock_session_cls.return_value.__aenter__ = AsyncMock(return_value=mock_session)
            mock_session_cls.return_value.__aexit__ = AsyncMock(return_value=None)
            mock_session.get.return_value = mock_resp

            async with mock_session as session:
                result = await client.fetch_one(session, "London")

        assert isinstance(result, WeatherData)
        assert result.city == "London"
        assert result.country == "GB"
        assert result.temperature_c == 15.0
        assert result.humidity == 75

    @pytest.mark.asyncio
    async def test_404_raises_weather_api_error(self, client):
        mock_resp = make_mock_response(404, {"message": "city not found"})

        with patch("aiohttp.ClientSession") as mock_session_cls:
            mock_session = AsyncMock()
            mock_session_cls.return_value.__aenter__ = AsyncMock(return_value=mock_session)
            mock_session_cls.return_value.__aexit__ = AsyncMock(return_value=None)
            mock_session.get.return_value = mock_resp

            async with mock_session as session:
                with pytest.raises(WeatherAPIError) as exc_info:
                    await client.fetch_one(session, "Nonexistentcity")

        assert exc_info.value.status == 404
        assert exc_info.value.city == "Nonexistentcity"

    @pytest.mark.asyncio
    async def test_401_raises_weather_api_error(self, client):
        mock_resp = make_mock_response(401, {"message": "Invalid API key"})

        with patch("aiohttp.ClientSession") as mock_session_cls:
            mock_session = AsyncMock()
            mock_session_cls.return_value.__aenter__ = AsyncMock(return_value=mock_session)
            mock_session_cls.return_value.__aexit__ = AsyncMock(return_value=None)
            mock_session.get.return_value = mock_resp

            async with mock_session as session:
                with pytest.raises(WeatherAPIError) as exc_info:
                    await client.fetch_one(session, "London")

        assert exc_info.value.status == 401


class TestWeatherDataParsing:

    def test_temperature_conversion(self):
        weather = WeatherData.from_api_response(SAMPLE_API_RESPONSE)
        assert weather.temperature_c == 15.0
        assert weather.temperature_f == 59.0

    def test_feels_like_conversion(self):
        weather = WeatherData.from_api_response(SAMPLE_API_RESPONSE)
        assert weather.feels_like_c == 13.0

    def test_city_and_country_parsed(self):
        weather = WeatherData.from_api_response(SAMPLE_API_RESPONSE)
        assert weather.city == "London"
        assert weather.country == "GB"

    def test_description_parsed(self):
        weather = WeatherData.from_api_response(SAMPLE_API_RESPONSE)
        assert weather.description == "light rain"
```

---

## tests/test_database.py

```python
# tests/test_database.py

import pytest
from datetime import datetime
from weather_cli.database import WeatherDatabase
from weather_cli.models import WeatherData


@pytest.fixture
def db(tmp_path):
    return WeatherDatabase(tmp_path / "test_weather.db")


@pytest.fixture
def sample_weather():
    return WeatherData(
        city="London",
        country="GB",
        temperature_c=15.0,
        feels_like_c=13.0,
        humidity=75,
        description="light rain",
        wind_speed=5.5,
        fetched_at=datetime.now(),
    )


@pytest.fixture
def populated_db(db, sample_weather):
    db.save(sample_weather)
    db.save(WeatherData(
        city="Paris",
        country="FR",
        temperature_c=18.0,
        feels_like_c=17.0,
        humidity=60,
        description="clear sky",
        wind_speed=3.0,
        fetched_at=datetime.now(),
    ))
    db.save(WeatherData(
        city="London",
        country="GB",
        temperature_c=12.0,
        feels_like_c=10.0,
        humidity=80,
        description="overcast",
        wind_speed=7.0,
        fetched_at=datetime.now(),
    ))
    return db


class TestSave:

    def test_save_stores_record(self, db, sample_weather):
        db.save(sample_weather)
        history = db.get_history()
        assert len(history) == 1

    def test_save_preserves_data(self, db, sample_weather):
        db.save(sample_weather)
        record = db.get_history()[0]
        assert record.city == "London"
        assert record.temperature_c == 15.0
        assert record.description == "light rain"

    def test_save_many(self, db, sample_weather):
        weathers = [sample_weather, sample_weather]
        db.save_many(weathers)
        assert len(db.get_history()) == 2


class TestGetHistory:

    def test_returns_all_records(self, populated_db):
        history = populated_db.get_history()
        assert len(history) == 3

    def test_filter_by_city(self, populated_db):
        london = populated_db.get_history(city="London")
        assert len(london) == 2
        assert all(r.city == "London" for r in london)

    def test_filter_case_insensitive(self, populated_db):
        london = populated_db.get_history(city="london")
        assert len(london) == 2

    def test_limit_results(self, populated_db):
        history = populated_db.get_history(limit=2)
        assert len(history) == 2

    def test_empty_db_returns_empty_list(self, db):
        assert db.get_history() == []


class TestGetStats:

    def test_stats_calculates_averages(self, populated_db):
        stats = populated_db.get_stats("London")
        assert stats["lookups"] == 2
        assert stats["avg_temp"] == 13.5    # (15 + 12) / 2
        assert stats["min_temp"] == 12.0
        assert stats["max_temp"] == 15.0

    def test_stats_returns_none_for_unknown_city(self, db):
        assert db.get_stats("Atlantis") is None


class TestClearHistory:

    def test_clear_all(self, populated_db):
        populated_db.clear_history()
        assert populated_db.get_history() == []

    def test_clear_by_city(self, populated_db):
        populated_db.clear_history("London")
        remaining = populated_db.get_history()
        assert len(remaining) == 1
        assert remaining[0].city == "Paris"
```

---

## Run it

```bash
# Run tests first — everything should pass without an API key
pytest tests/ -v

# Run the application
python main.py
```

Test session:

```
weather London
weather "New York" Tokyo Berlin --fahrenheit
history
history London
stats London
history --limit 5
weather FakeCity123
clear London
history
quit
```

---

## What this project demonstrates

**Async pays off immediately.** Fetching 4 cities takes the same time as fetching 1. Remove `asyncio.gather` and replace with sequential calls — you'll feel the difference.

**Semaphores prevent abuse.** The `max_concurrent=5` cap means the API client is polite regardless of how many cities are requested. Pass 100 cities — still only 5 requests in flight at once.

**The database earns its place.** Try `stats London` after a few lookups. That aggregation query over history is instant because of the index on `city`. With JSON you'd load everything and compute in Python.

**Mocking makes tests fast.** The test suite runs in under a second with no network calls. Every API scenario — success, 404, 401, timeout — is tested without touching the real API.

**Separation pays off.** Want to swap OpenWeatherMap for a different API? Change `api.py` only. Want to swap SQLite for Postgres? Change `database.py` only. The CLI and models don't care.

---

Week 3 complete. You've covered decorators, generators, APIs, testing, concurrency, and databases — and built an application that uses all of them.

Week 4 starts with Day 22 — type hints, dataclasses in depth, and Pydantic. The tools that make Python code safer and more self-documenting. Ready when you are.

[[Intermediate Power]]