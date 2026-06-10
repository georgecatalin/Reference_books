#### Build and Ship a Complete Python Application

This is the final day. Everything from the course in one application, built from scratch, structured properly, and ready to ship.

---

## What you're building

A **Personal Finance Tracker** — a FastAPI application with a CLI, SQLite persistence, async operations, full test suite, proper architecture, and CI/CD configuration.

Why this project: it touches every skill from the course and produces something genuinely useful.

**Features:**

- Add income and expense transactions
- Categorize transactions
- Query by date range, category, amount
- Monthly and category summaries
- CSV import
- REST API + CLI interface
- Full test suite

---

## Project structure

```
finance_tracker/
├── src/
│   └── finance_tracker/
│       ├── __init__.py
│       ├── domain/
│       │   ├── __init__.py
│       │   ├── transaction.py
│       │   └── exceptions.py
│       ├── repositories/
│       │   ├── __init__.py
│       │   ├── base.py
│       │   ├── sqlite_repository.py
│       │   └── memory_repository.py
│       ├── services/
│       │   ├── __init__.py
│       │   ├── transaction_service.py
│       │   └── import_service.py
│       ├── api/
│       │   ├── __init__.py
│       │   ├── app.py
│       │   ├── routes.py
│       │   └── schemas.py
│       ├── cli/
│       │   ├── __init__.py
│       │   └── commands.py
│       └── infrastructure/
│           ├── __init__.py
│           ├── config.py
│           ├── database.py
│           └── container.py
├── tests/
│   ├── conftest.py
│   ├── unit/
│   │   ├── test_transaction_service.py
│   │   └── test_import_service.py
│   └── integration/
│       └── test_api.py
├── pyproject.toml
├── .env
├── .gitignore
├── .pre-commit-config.yaml
├── Makefile
└── main.py
```

```bash
mkdir -p finance_tracker/src/finance_tracker/{domain,repositories,services,api,cli,infrastructure}
mkdir -p finance_tracker/tests/{unit,integration}
cd finance_tracker
touch src/finance_tracker/{domain,repositories,services,api,cli,infrastructure}/__init__.py
touch tests/{conftest.py,unit/test_transaction_service.py,unit/test_import_service.py,integration/test_api.py}
```

---

## Setup

```toml
# pyproject.toml

[build-system]
requires = ["hatchling"]
build-backend = "hatchling.build"

[project]
name = "finance-tracker"
version = "0.1.0"
description = "Personal finance tracker with REST API and CLI"
readme = "README.md"
requires-python = ">=3.11"
license = {text = "MIT"}
dependencies = [
    "fastapi>=0.110.0",
    "uvicorn[standard]>=0.27.0",
    "pydantic>=2.0.0",
    "pydantic-settings>=2.0.0",
]

[project.optional-dependencies]
dev = [
    "pytest>=7.4.0",
    "pytest-cov>=4.0.0",
    "pytest-asyncio>=0.23.0",
    "httpx>=0.26.0",
    "ruff>=0.1.9",
    "mypy>=1.8.0",
    "pre-commit>=3.6.0",
]

[project.scripts]
finance = "finance_tracker.cli.commands:main"

[tool.hatchling.build.targets.wheel]
packages = ["src/finance_tracker"]

[tool.pytest.ini_options]
testpaths = ["tests"]
addopts = ["-v", "--tb=short", "--strict-markers"]
markers = ["unit", "integration", "slow"]

[tool.coverage.run]
source = ["src"]
branch = true

[tool.coverage.report]
show_missing = true
fail_under = 80

[tool.ruff]
line-length = 88
target-version = "py311"
src = ["src", "tests"]

[tool.ruff.lint]
select = ["E", "W", "F", "I", "N", "UP", "B", "C4", "SIM", "RUF"]
ignore = ["E501", "B008"]

[tool.ruff.lint.per-file-ignores]
"tests/*" = ["S101"]

[tool.mypy]
python_version = "3.11"
disallow_untyped_defs = true
ignore_missing_imports = true
```

```bash
python -m venv venv && source venv/bin/activate
pip install -e ".[dev]"
```

---

## Domain layer

```python
# src/finance_tracker/domain/transaction.py

from dataclasses import dataclass, field
from datetime import date
from decimal import Decimal
from enum import Enum
from typing import Optional


class TransactionType(str, Enum):
    INCOME = "income"
    EXPENSE = "expense"


class Category(str, Enum):
    # Income
    SALARY = "salary"
    FREELANCE = "freelance"
    INVESTMENT = "investment"
    OTHER_INCOME = "other_income"
    # Expense
    HOUSING = "housing"
    FOOD = "food"
    TRANSPORT = "transport"
    UTILITIES = "utilities"
    ENTERTAINMENT = "entertainment"
    HEALTH = "health"
    EDUCATION = "education"
    OTHER_EXPENSE = "other_expense"

    @property
    def transaction_type(self) -> TransactionType:
        income_categories = {
            Category.SALARY, Category.FREELANCE,
            Category.INVESTMENT, Category.OTHER_INCOME,
        }
        return (
            TransactionType.INCOME
            if self in income_categories
            else TransactionType.EXPENSE
        )


@dataclass
class Transaction:
    id: int
    amount: Decimal
    category: Category
    description: str
    transaction_date: date
    transaction_type: TransactionType = field(init=False)
    notes: Optional[str] = None
    created_at: date = field(default_factory=date.today)

    def __post_init__(self) -> None:
        self.transaction_type = self.category.transaction_type
        if self.amount <= 0:
            raise ValueError(f"Amount must be positive, got {self.amount}")
        if not self.description.strip():
            raise ValueError("Description cannot be empty")
        self.description = self.description.strip()

    @property
    def signed_amount(self) -> Decimal:
        """Positive for income, negative for expense."""
        return (
            self.amount
            if self.transaction_type == TransactionType.INCOME
            else -self.amount
        )

    def __repr__(self) -> str:
        sign = "+" if self.transaction_type == TransactionType.INCOME else "-"
        return (
            f"Transaction(id={self.id}, {sign}£{self.amount:.2f}, "
            f"{self.category.value}, {self.transaction_date})"
        )
```

```python
# src/finance_tracker/domain/exceptions.py

class FinanceError(Exception):
    pass

class TransactionNotFoundError(FinanceError):
    def __init__(self, transaction_id: int) -> None:
        self.transaction_id = transaction_id
        super().__init__(f"Transaction {transaction_id} not found")

class InvalidTransactionError(FinanceError):
    pass

class ImportError(FinanceError):
    def __init__(self, row: int, reason: str) -> None:
        self.row = row
        self.reason = reason
        super().__init__(f"Import failed at row {row}: {reason}")
```

---

## Repository layer

```python
# src/finance_tracker/repositories/base.py

from abc import ABC, abstractmethod
from datetime import date
from decimal import Decimal
from typing import Optional
from finance_tracker.domain.transaction import Transaction, Category, TransactionType


class TransactionRepository(ABC):

    @abstractmethod
    def get_by_id(self, transaction_id: int) -> Optional[Transaction]: ...

    @abstractmethod
    def get_all(
        self,
        transaction_type: Optional[TransactionType] = None,
        category: Optional[Category] = None,
        start_date: Optional[date] = None,
        end_date: Optional[date] = None,
        min_amount: Optional[Decimal] = None,
        max_amount: Optional[Decimal] = None,
    ) -> list[Transaction]: ...

    @abstractmethod
    def save(self, transaction: Transaction) -> Transaction: ...

    @abstractmethod
    def delete(self, transaction_id: int) -> bool: ...

    @abstractmethod
    def get_monthly_summary(self, year: int, month: int) -> dict: ...

    @abstractmethod
    def get_category_summary(
        self,
        start_date: Optional[date] = None,
        end_date: Optional[date] = None,
    ) -> list[dict]: ...
```

```python
# src/finance_tracker/repositories/sqlite_repository.py

import sqlite3
from contextlib import contextmanager
from datetime import date
from decimal import Decimal
from pathlib import Path
from typing import Optional

from finance_tracker.domain.transaction import Transaction, Category, TransactionType
from finance_tracker.repositories.base import TransactionRepository


SCHEMA = """
CREATE TABLE IF NOT EXISTS transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    amount TEXT NOT NULL,
    category TEXT NOT NULL,
    description TEXT NOT NULL,
    transaction_date TEXT NOT NULL,
    transaction_type TEXT NOT NULL,
    notes TEXT,
    created_at TEXT NOT NULL DEFAULT (date('now'))
);
CREATE INDEX IF NOT EXISTS idx_date ON transactions(transaction_date);
CREATE INDEX IF NOT EXISTS idx_category ON transactions(category);
CREATE INDEX IF NOT EXISTS idx_type ON transactions(transaction_type);
"""


class SQLiteTransactionRepository(TransactionRepository):

    def __init__(self, db_path: str | Path = "finance.db") -> None:
        self._db_path = str(db_path)
        self._init()

    @contextmanager
    def _db(self):
        conn = sqlite3.connect(self._db_path)
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

    def _init(self) -> None:
        with self._db() as conn:
            conn.executescript(SCHEMA)

    def _row_to_transaction(self, row: sqlite3.Row) -> Transaction:
        t = Transaction.__new__(Transaction)
        t.id = row["id"]
        t.amount = Decimal(row["amount"])
        t.category = Category(row["category"])
        t.description = row["description"]
        t.transaction_date = date.fromisoformat(row["transaction_date"])
        t.transaction_type = TransactionType(row["transaction_type"])
        t.notes = row["notes"]
        t.created_at = date.fromisoformat(row["created_at"])
        return t

    def get_by_id(self, transaction_id: int) -> Optional[Transaction]:
        with self._db() as conn:
            row = conn.execute(
                "SELECT * FROM transactions WHERE id = ?", (transaction_id,)
            ).fetchone()
        return self._row_to_transaction(row) if row else None

    def get_all(
        self,
        transaction_type: Optional[TransactionType] = None,
        category: Optional[Category] = None,
        start_date: Optional[date] = None,
        end_date: Optional[date] = None,
        min_amount: Optional[Decimal] = None,
        max_amount: Optional[Decimal] = None,
    ) -> list[Transaction]:
        conditions: list[str] = []
        params: list = []

        if transaction_type:
            conditions.append("transaction_type = ?")
            params.append(transaction_type.value)
        if category:
            conditions.append("category = ?")
            params.append(category.value)
        if start_date:
            conditions.append("transaction_date >= ?")
            params.append(start_date.isoformat())
        if end_date:
            conditions.append("transaction_date <= ?")
            params.append(end_date.isoformat())
        if min_amount:
            conditions.append("CAST(amount AS REAL) >= ?")
            params.append(float(min_amount))
        if max_amount:
            conditions.append("CAST(amount AS REAL) <= ?")
            params.append(float(max_amount))

        where = f"WHERE {' AND '.join(conditions)}" if conditions else ""
        with self._db() as conn:
            rows = conn.execute(
                f"SELECT * FROM transactions {where} ORDER BY transaction_date DESC, id DESC",
                params
            ).fetchall()

        return [self._row_to_transaction(r) for r in rows]

    def save(self, transaction: Transaction) -> Transaction:
        with self._db() as conn:
            if transaction.id == 0:
                cursor = conn.execute(
                    """INSERT INTO transactions
                       (amount, category, description, transaction_date,
                        transaction_type, notes)
                       VALUES (?, ?, ?, ?, ?, ?)""",
                    (str(transaction.amount), transaction.category.value,
                     transaction.description, transaction.transaction_date.isoformat(),
                     transaction.transaction_type.value, transaction.notes)
                )
                transaction.id = cursor.lastrowid
            else:
                conn.execute(
                    """UPDATE transactions
                       SET amount=?, category=?, description=?,
                           transaction_date=?, notes=?
                       WHERE id=?""",
                    (str(transaction.amount), transaction.category.value,
                     transaction.description, transaction.transaction_date.isoformat(),
                     transaction.notes, transaction.id)
                )
        return transaction

    def delete(self, transaction_id: int) -> bool:
        with self._db() as conn:
            cursor = conn.execute(
                "DELETE FROM transactions WHERE id = ?", (transaction_id,)
            )
        return cursor.rowcount > 0

    def get_monthly_summary(self, year: int, month: int) -> dict:
        with self._db() as conn:
            row = conn.execute("""
                SELECT
                    SUM(CASE WHEN transaction_type='income'
                        THEN CAST(amount AS REAL) ELSE 0 END) as income,
                    SUM(CASE WHEN transaction_type='expense'
                        THEN CAST(amount AS REAL) ELSE 0 END) as expenses,
                    COUNT(*) as count
                FROM transactions
                WHERE strftime('%Y-%m', transaction_date) = ?
            """, (f"{year:04d}-{month:02d}",)).fetchone()

        income = Decimal(str(row["income"] or 0))
        expenses = Decimal(str(row["expenses"] or 0))
        return {
            "year": year,
            "month": month,
            "income": income,
            "expenses": expenses,
            "net": income - expenses,
            "transaction_count": row["count"],
        }

    def get_category_summary(
        self,
        start_date: Optional[date] = None,
        end_date: Optional[date] = None,
    ) -> list[dict]:
        conditions: list[str] = []
        params: list = []
        if start_date:
            conditions.append("transaction_date >= ?")
            params.append(start_date.isoformat())
        if end_date:
            conditions.append("transaction_date <= ?")
            params.append(end_date.isoformat())
        where = f"WHERE {' AND '.join(conditions)}" if conditions else ""

        with self._db() as conn:
            rows = conn.execute(f"""
                SELECT
                    category,
                    transaction_type,
                    COUNT(*) as count,
                    SUM(CAST(amount AS REAL)) as total
                FROM transactions {where}
                GROUP BY category, transaction_type
                ORDER BY total DESC
            """, params).fetchall()

        return [
            {
                "category": row["category"],
                "transaction_type": row["transaction_type"],
                "count": row["count"],
                "total": Decimal(str(row["total"])),
            }
            for row in rows
        ]
```

```python
# src/finance_tracker/repositories/memory_repository.py

from datetime import date
from decimal import Decimal
from typing import Optional
from finance_tracker.domain.transaction import Transaction, Category, TransactionType
from finance_tracker.repositories.base import TransactionRepository


class InMemoryTransactionRepository(TransactionRepository):

    def __init__(self) -> None:
        self._transactions: dict[int, Transaction] = {}
        self._next_id = 1

    def get_by_id(self, transaction_id: int) -> Optional[Transaction]:
        return self._transactions.get(transaction_id)

    def get_all(
        self,
        transaction_type: Optional[TransactionType] = None,
        category: Optional[Category] = None,
        start_date: Optional[date] = None,
        end_date: Optional[date] = None,
        min_amount: Optional[Decimal] = None,
        max_amount: Optional[Decimal] = None,
    ) -> list[Transaction]:
        txns = list(self._transactions.values())
        if transaction_type:
            txns = [t for t in txns if t.transaction_type == transaction_type]
        if category:
            txns = [t for t in txns if t.category == category]
        if start_date:
            txns = [t for t in txns if t.transaction_date >= start_date]
        if end_date:
            txns = [t for t in txns if t.transaction_date <= end_date]
        if min_amount:
            txns = [t for t in txns if t.amount >= min_amount]
        if max_amount:
            txns = [t for t in txns if t.amount <= max_amount]
        return sorted(txns, key=lambda t: (t.transaction_date, t.id), reverse=True)

    def save(self, transaction: Transaction) -> Transaction:
        if transaction.id == 0:
            transaction.id = self._next_id
            self._next_id += 1
        self._transactions[transaction.id] = transaction
        return transaction

    def delete(self, transaction_id: int) -> bool:
        if transaction_id in self._transactions:
            del self._transactions[transaction_id]
            return True
        return False

    def get_monthly_summary(self, year: int, month: int) -> dict:
        txns = [
            t for t in self._transactions.values()
            if t.transaction_date.year == year
            and t.transaction_date.month == month
        ]
        income = sum(
            t.amount for t in txns
            if t.transaction_type == TransactionType.INCOME
        )
        expenses = sum(
            t.amount for t in txns
            if t.transaction_type == TransactionType.EXPENSE
        )
        return {
            "year": year, "month": month,
            "income": Decimal(str(income)),
            "expenses": Decimal(str(expenses)),
            "net": Decimal(str(income - expenses)),
            "transaction_count": len(txns),
        }

    def get_category_summary(
        self,
        start_date: Optional[date] = None,
        end_date: Optional[date] = None,
    ) -> list[dict]:
        txns = self.get_all(start_date=start_date, end_date=end_date)
        summary: dict[str, dict] = {}
        for t in txns:
            key = t.category.value
            if key not in summary:
                summary[key] = {
                    "category": key,
                    "transaction_type": t.transaction_type.value,
                    "count": 0,
                    "total": Decimal("0"),
                }
            summary[key]["count"] += 1
            summary[key]["total"] += t.amount
        return sorted(summary.values(), key=lambda x: x["total"], reverse=True)
```

---

## Service layer

```python
# src/finance_tracker/services/transaction_service.py

from datetime import date
from decimal import Decimal, InvalidOperation
from typing import Optional

from finance_tracker.domain.transaction import Transaction, Category, TransactionType
from finance_tracker.domain.exceptions import (
    TransactionNotFoundError, InvalidTransactionError
)
from finance_tracker.repositories.base import TransactionRepository


class TransactionService:

    def __init__(self, repository: TransactionRepository) -> None:
        self._repo = repository

    def add_transaction(
        self,
        amount: str | Decimal,
        category: str,
        description: str,
        transaction_date: str | date | None = None,
        notes: Optional[str] = None,
    ) -> Transaction:
        try:
            decimal_amount = Decimal(str(amount)).quantize(Decimal("0.01"))
        except InvalidOperation:
            raise InvalidTransactionError(f"Invalid amount: {amount!r}")

        try:
            category_enum = Category(category.lower())
        except ValueError:
            valid = [c.value for c in Category]
            raise InvalidTransactionError(
                f"Invalid category {category!r}. Valid: {valid}"
            )

        if isinstance(transaction_date, str):
            try:
                parsed_date = date.fromisoformat(transaction_date)
            except ValueError:
                raise InvalidTransactionError(
                    f"Invalid date {transaction_date!r}. Use YYYY-MM-DD format."
                )
        elif transaction_date is None:
            parsed_date = date.today()
        else:
            parsed_date = transaction_date

        try:
            transaction = Transaction(
                id=0,
                amount=decimal_amount,
                category=category_enum,
                description=description,
                transaction_date=parsed_date,
                notes=notes,
            )
        except ValueError as e:
            raise InvalidTransactionError(str(e)) from e

        return self._repo.save(transaction)

    def get_transaction(self, transaction_id: int) -> Transaction:
        txn = self._repo.get_by_id(transaction_id)
        if txn is None:
            raise TransactionNotFoundError(transaction_id)
        return txn

    def list_transactions(
        self,
        transaction_type: Optional[str] = None,
        category: Optional[str] = None,
        start_date: Optional[str] = None,
        end_date: Optional[str] = None,
        min_amount: Optional[str] = None,
        max_amount: Optional[str] = None,
    ) -> list[Transaction]:
        type_enum = TransactionType(transaction_type) if transaction_type else None
        cat_enum = Category(category) if category else None
        start = date.fromisoformat(start_date) if start_date else None
        end = date.fromisoformat(end_date) if end_date else None
        min_amt = Decimal(min_amount) if min_amount else None
        max_amt = Decimal(max_amount) if max_amount else None

        return self._repo.get_all(
            transaction_type=type_enum,
            category=cat_enum,
            start_date=start,
            end_date=end,
            min_amount=min_amt,
            max_amount=max_amt,
        )

    def delete_transaction(self, transaction_id: int) -> None:
        if not self._repo.delete(transaction_id):
            raise TransactionNotFoundError(transaction_id)

    def get_monthly_summary(self, year: int, month: int) -> dict:
        if not (1 <= month <= 12):
            raise InvalidTransactionError(f"Invalid month: {month}")
        return self._repo.get_monthly_summary(year, month)

    def get_category_summary(
        self,
        start_date: Optional[str] = None,
        end_date: Optional[str] = None,
    ) -> list[dict]:
        start = date.fromisoformat(start_date) if start_date else None
        end = date.fromisoformat(end_date) if end_date else None
        return self._repo.get_category_summary(start_date=start, end_date=end)

    def get_balance(self) -> dict:
        all_txns = self._repo.get_all()
        income = sum(
            t.amount for t in all_txns
            if t.transaction_type == TransactionType.INCOME
        )
        expenses = sum(
            t.amount for t in all_txns
            if t.transaction_type == TransactionType.EXPENSE
        )
        return {
            "total_income": Decimal(str(income)),
            "total_expenses": Decimal(str(expenses)),
            "balance": Decimal(str(income - expenses)),
            "transaction_count": len(all_txns),
        }
```

```python
# src/finance_tracker/services/import_service.py

import csv
from io import StringIO
from dataclasses import dataclass
from typing import Optional
from finance_tracker.services.transaction_service import TransactionService
from finance_tracker.domain.exceptions import InvalidTransactionError
from finance_tracker.domain.exceptions import ImportError as ImportFinanceError


@dataclass
class ImportResult:
    total_rows: int
    imported: int
    failed: int
    errors: list[dict]

    @property
    def success_rate(self) -> float:
        if self.total_rows == 0:
            return 0.0
        return self.imported / self.total_rows * 100


class CSVImportService:
    """
    Imports transactions from CSV.
    Expected columns: date, amount, category, description, notes (optional)
    """

    REQUIRED_COLUMNS = {"date", "amount", "category", "description"}

    def __init__(self, transaction_service: TransactionService) -> None:
        self._service = transaction_service

    def import_csv(self, csv_content: str) -> ImportResult:
        reader = csv.DictReader(StringIO(csv_content))

        if reader.fieldnames is None:
            raise ImportFinanceError(0, "Empty CSV file")

        missing = self.REQUIRED_COLUMNS - {
            f.strip().lower() for f in reader.fieldnames
        }
        if missing:
            raise ImportFinanceError(
                0, f"Missing required columns: {missing}"
            )

        imported = 0
        errors = []

        for row_num, row in enumerate(reader, start=2):
            try:
                self._service.add_transaction(
                    amount=row["amount"].strip(),
                    category=row["category"].strip(),
                    description=row["description"].strip(),
                    transaction_date=row["date"].strip(),
                    notes=row.get("notes", "").strip() or None,
                )
                imported += 1
            except (InvalidTransactionError, KeyError, ValueError) as e:
                errors.append({"row": row_num, "data": dict(row), "error": str(e)})

        total = imported + len(errors)
        return ImportResult(
            total_rows=total,
            imported=imported,
            failed=len(errors),
            errors=errors,
        )
```

---

## Infrastructure layer

```python
# src/finance_tracker/infrastructure/config.py

from functools import lru_cache
from pydantic import Field
from pydantic_settings import BaseSettings


class Settings(BaseSettings):
    app_name: str = "Finance Tracker"
    app_version: str = "0.1.0"
    debug: bool = False
    environment: str = "development"
    database_url: str = "sqlite:///finance.db"
    api_host: str = "0.0.0.0"
    api_port: int = Field(default=8000, ge=1, le=65535)

    model_config = {
        "env_file": ".env",
        "case_sensitive": False,
    }

    @property
    def db_path(self) -> str:
        if self.database_url.startswith("sqlite:///"):
            return self.database_url[10:]
        return "finance.db"


@lru_cache
def get_settings() -> Settings:
    return Settings()
```

```python
# src/finance_tracker/infrastructure/container.py

from functools import lru_cache
from finance_tracker.infrastructure.config import get_settings
from finance_tracker.repositories.base import TransactionRepository
from finance_tracker.repositories.sqlite_repository import SQLiteTransactionRepository
from finance_tracker.services.transaction_service import TransactionService
from finance_tracker.services.import_service import CSVImportService


class Container:

    def __init__(self) -> None:
        self._settings = get_settings()
        self._repository: TransactionRepository | None = None
        self._transaction_service: TransactionService | None = None
        self._import_service: CSVImportService | None = None

    @property
    def repository(self) -> TransactionRepository:
        if self._repository is None:
            self._repository = SQLiteTransactionRepository(
                self._settings.db_path
            )
        return self._repository

    @property
    def transaction_service(self) -> TransactionService:
        if self._transaction_service is None:
            self._transaction_service = TransactionService(self.repository)
        return self._transaction_service

    @property
    def import_service(self) -> CSVImportService:
        if self._import_service is None:
            self._import_service = CSVImportService(self.transaction_service)
        return self._import_service


@lru_cache
def get_container() -> Container:
    return Container()


def get_transaction_service() -> TransactionService:
    return get_container().transaction_service


def get_import_service() -> CSVImportService:
    return get_container().import_service
```

---

## API layer

```python
# src/finance_tracker/api/schemas.py

from pydantic import BaseModel, Field, field_validator
from decimal import Decimal
from datetime import date
from typing import Optional
from finance_tracker.domain.transaction import Category, TransactionType


class CreateTransactionRequest(BaseModel):
    amount: Decimal = Field(gt=0, decimal_places=2)
    category: str
    description: str = Field(min_length=1, max_length=300)
    transaction_date: Optional[str] = None
    notes: Optional[str] = None

    @field_validator("category")
    @classmethod
    def validate_category(cls, v: str) -> str:
        valid = [c.value for c in Category]
        if v.lower() not in valid:
            raise ValueError(f"Must be one of: {valid}")
        return v.lower()

    @field_validator("description")
    @classmethod
    def strip_description(cls, v: str) -> str:
        return v.strip()


class TransactionResponse(BaseModel):
    id: int
    amount: Decimal
    category: str
    description: str
    transaction_date: str
    transaction_type: str
    notes: Optional[str]

    model_config = {"from_attributes": True}

    @classmethod
    def from_domain(cls, t) -> "TransactionResponse":
        return cls(
            id=t.id,
            amount=t.amount,
            category=t.category.value,
            description=t.description,
            transaction_date=t.transaction_date.isoformat(),
            transaction_type=t.transaction_type.value,
            notes=t.notes,
        )


class MonthlySummaryResponse(BaseModel):
    year: int
    month: int
    income: Decimal
    expenses: Decimal
    net: Decimal
    transaction_count: int


class CategorySummaryItem(BaseModel):
    category: str
    transaction_type: str
    count: int
    total: Decimal


class BalanceResponse(BaseModel):
    total_income: Decimal
    total_expenses: Decimal
    balance: Decimal
    transaction_count: int


class ImportResponse(BaseModel):
    total_rows: int
    imported: int
    failed: int
    success_rate: float
    errors: list[dict]
```

```python
# src/finance_tracker/api/routes.py

from fastapi import APIRouter, Depends, HTTPException, UploadFile, File, Query
from typing import Optional
from functools import wraps

from finance_tracker.services.transaction_service import TransactionService
from finance_tracker.services.import_service import CSVImportService
from finance_tracker.domain.exceptions import (
    TransactionNotFoundError, InvalidTransactionError
)
from finance_tracker.api.schemas import (
    CreateTransactionRequest, TransactionResponse,
    MonthlySummaryResponse, CategorySummaryItem,
    BalanceResponse, ImportResponse,
)
from finance_tracker.infrastructure.container import (
    get_transaction_service, get_import_service
)


router = APIRouter(prefix="/transactions", tags=["transactions"])


def handle_domain_errors(func):
    @wraps(func)
    def wrapper(*args, **kwargs):
        try:
            return func(*args, **kwargs)
        except TransactionNotFoundError as e:
            raise HTTPException(status_code=404, detail=str(e))
        except InvalidTransactionError as e:
            raise HTTPException(status_code=422, detail=str(e))
    return wrapper


@router.post("", response_model=TransactionResponse, status_code=201)
@handle_domain_errors
def create_transaction(
    request: CreateTransactionRequest,
    service: TransactionService = Depends(get_transaction_service),
):
    txn = service.add_transaction(
        amount=request.amount,
        category=request.category,
        description=request.description,
        transaction_date=request.transaction_date,
        notes=request.notes,
    )
    return TransactionResponse.from_domain(txn)


@router.get("", response_model=list[TransactionResponse])
@handle_domain_errors
def list_transactions(
    type: Optional[str] = Query(default=None, alias="type"),
    category: Optional[str] = None,
    start_date: Optional[str] = None,
    end_date: Optional[str] = None,
    min_amount: Optional[str] = None,
    max_amount: Optional[str] = None,
    service: TransactionService = Depends(get_transaction_service),
):
    txns = service.list_transactions(
        transaction_type=type,
        category=category,
        start_date=start_date,
        end_date=end_date,
        min_amount=min_amount,
        max_amount=max_amount,
    )
    return [TransactionResponse.from_domain(t) for t in txns]


@router.get("/balance", response_model=BalanceResponse)
def get_balance(service: TransactionService = Depends(get_transaction_service)):
    return service.get_balance()


@router.get("/summary/monthly", response_model=MonthlySummaryResponse)
@handle_domain_errors
def monthly_summary(
    year: int = Query(ge=2000, le=2100),
    month: int = Query(ge=1, le=12),
    service: TransactionService = Depends(get_transaction_service),
):
    return service.get_monthly_summary(year, month)


@router.get("/summary/category", response_model=list[CategorySummaryItem])
def category_summary(
    start_date: Optional[str] = None,
    end_date: Optional[str] = None,
    service: TransactionService = Depends(get_transaction_service),
):
    return service.get_category_summary(start_date, end_date)


@router.get("/{transaction_id}", response_model=TransactionResponse)
@handle_domain_errors
def get_transaction(
    transaction_id: int,
    service: TransactionService = Depends(get_transaction_service),
):
    txn = service.get_transaction(transaction_id)
    return TransactionResponse.from_domain(txn)


@router.delete("/{transaction_id}", status_code=204)
@handle_domain_errors
def delete_transaction(
    transaction_id: int,
    service: TransactionService = Depends(get_transaction_service),
):
    service.delete_transaction(transaction_id)


@router.post("/import/csv", response_model=ImportResponse)
def import_csv(
    file: UploadFile = File(...),
    import_svc: CSVImportService = Depends(get_import_service),
):
    if not file.filename or not file.filename.endswith(".csv"):
        raise HTTPException(status_code=400, detail="File must be a .csv")
    content = file.file.read().decode("utf-8")
    result = import_svc.import_csv(content)
    return ImportResponse(
        total_rows=result.total_rows,
        imported=result.imported,
        failed=result.failed,
        success_rate=round(result.success_rate, 1),
        errors=result.errors,
    )
```

```python
# src/finance_tracker/api/app.py

from fastapi import FastAPI, Request
from fastapi.responses import JSONResponse
from fastapi.middleware.cors import CORSMiddleware
from finance_tracker.api.routes import router
from finance_tracker.infrastructure.config import get_settings


def create_app() -> FastAPI:
    settings = get_settings()

    app = FastAPI(
        title=settings.app_name,
        version=settings.app_version,
        debug=settings.debug,
    )

    app.add_middleware(
        CORSMiddleware,
        allow_origins=["*"],
        allow_methods=["*"],
        allow_headers=["*"],
    )

    app.include_router(router, prefix="/api/v1")

    @app.get("/")
    def root():
        return {"name": settings.app_name, "version": settings.app_version}

    @app.get("/health")
    def health():
        return {"status": "ok"}

    @app.exception_handler(404)
    async def not_found(request: Request, exc):
        return JSONResponse(
            status_code=404,
            content={"error": "not_found", "path": str(request.url.path)}
        )

    return app


app = create_app()
```

---

## CLI layer

```python
# src/finance_tracker/cli/commands.py

import sys
from datetime import date
from finance_tracker.infrastructure.container import get_container
from finance_tracker.domain.transaction import Category, TransactionType
from finance_tracker.domain.exceptions import (
    TransactionNotFoundError, InvalidTransactionError
)


def format_amount(amount, txn_type: str) -> str:
    symbol = "+" if txn_type == "income" else "-"
    return f"{symbol}£{amount:.2f}"


def print_transaction(t) -> None:
    amount_str = format_amount(t.amount, t.transaction_type.value)
    print(
        f"  [{t.id:>4}] {t.transaction_date}  "
        f"{amount_str:>10}  "
        f"{t.category.value:<20}  {t.description}"
    )


def cmd_add(service, args: list[str]) -> None:
    if len(args) < 3:
        print("  Usage: add <amount> <category> <description> [date] [notes]")
        print("  Example: add 2500 salary 'Monthly salary' 2024-01-31")
        return
    try:
        t = service.add_transaction(
            amount=args[0],
            category=args[1],
            description=args[2],
            transaction_date=args[3] if len(args) > 3 else None,
            notes=args[4] if len(args) > 4 else None,
        )
        sign = "+" if t.transaction_type == TransactionType.INCOME else "-"
        print(f"\n  Added: [{t.id}] {sign}£{t.amount:.2f} — {t.description}\n")
    except (InvalidTransactionError, Exception) as e:
        print(f"\n  Error: {e}\n")


def cmd_list(service, args: list[str]) -> None:
    kwargs = {}
    i = 0
    while i < len(args):
        if args[i] == "--type" and i + 1 < len(args):
            kwargs["transaction_type"] = args[i + 1]; i += 2
        elif args[i] == "--category" and i + 1 < len(args):
            kwargs["category"] = args[i + 1]; i += 2
        elif args[i] == "--from" and i + 1 < len(args):
            kwargs["start_date"] = args[i + 1]; i += 2
        elif args[i] == "--to" and i + 1 < len(args):
            kwargs["end_date"] = args[i + 1]; i += 2
        else:
            i += 1

    try:
        transactions = service.list_transactions(**kwargs)
        if not transactions:
            print("\n  No transactions found.\n")
            return

        print(f"\n  {'ID':>5}  {'DATE':<12}  {'AMOUNT':>10}  {'CATEGORY':<20}  DESCRIPTION")
        print("  " + "-" * 70)
        for t in transactions:
            print_transaction(t)
        print()
    except Exception as e:
        print(f"\n  Error: {e}\n")


def cmd_balance(service) -> None:
    b = service.get_balance()
    print(f"""
  ┌─────────────────────────────┐
  │  BALANCE SUMMARY            │
  ├─────────────────────────────┤
  │  Total income:  £{b['total_income']:>10.2f} │
  │  Total expenses:£{b['total_expenses']:>10.2f} │
  │  ─────────────────────────  │
  │  Net balance:   £{b['balance']:>10.2f} │
  │  Transactions:  {b['transaction_count']:>10}  │
  └─────────────────────────────┘
""")


def cmd_summary(service, args: list[str]) -> None:
    today = date.today()
    year = int(args[0]) if len(args) > 0 else today.year
    month = int(args[1]) if len(args) > 1 else today.month

    try:
        s = service.get_monthly_summary(year, month)
        import calendar
        month_name = calendar.month_name[month]
        print(f"""
  {month_name} {year}
  ─────────────────────────
  Income:       £{s['income']:>10.2f}
  Expenses:     £{s['expenses']:>10.2f}
  Net:          £{s['net']:>10.2f}
  Transactions:  {s['transaction_count']:>9}
""")
    except Exception as e:
        print(f"\n  Error: {e}\n")


def cmd_categories(service) -> None:
    items = service.get_category_summary()
    if not items:
        print("\n  No data.\n")
        return
    print(f"\n  {'CATEGORY':<22} {'TYPE':<10} {'COUNT':>6} {'TOTAL':>12}")
    print("  " + "-" * 55)
    for item in items:
        print(
            f"  {item['category']:<22} "
            f"{item['transaction_type']:<10} "
            f"{item['count']:>6} "
            f"  £{item['total']:>10.2f}"
        )
    print()


def cmd_delete(service, args: list[str]) -> None:
    if not args:
        print("  Usage: delete <id>")
        return
    try:
        txn_id = int(args[0])
        service.delete_transaction(txn_id)
        print(f"\n  Deleted transaction {txn_id}.\n")
    except (TransactionNotFoundError, ValueError) as e:
        print(f"\n  Error: {e}\n")


def cmd_import(container, args: list[str]) -> None:
    if not args:
        print("  Usage: import <path/to/file.csv>")
        return
    from pathlib import Path
    path = Path(args[0])
    if not path.exists():
        print(f"\n  File not found: {path}\n")
        return
    content = path.read_text(encoding="utf-8")
    result = container.import_service.import_csv(content)
    print(f"""
  Import complete:
    Imported: {result.imported}
    Failed:   {result.failed}
    Total:    {result.total_rows}
    Success:  {result.success_rate:.1f}%
""")
    if result.errors:
        print("  Errors:")
        for err in result.errors[:5]:
            print(f"    Row {err['row']}: {err['error']}")


def show_help() -> None:
    print("""
  Finance Tracker CLI

  Commands:
    add <amount> <category> <description> [date] [notes]
    list [--type income|expense] [--category CATEGORY]
         [--from YYYY-MM-DD] [--to YYYY-MM-DD]
    balance               Show overall balance
    summary [year] [month] Monthly summary
    categories            Spending by category
    delete <id>           Delete a transaction
    import <file.csv>     Import from CSV
    categories            Show valid categories
    help                  Show this message
    quit                  Exit

  Categories:
    Income:  salary, freelance, investment, other_income
    Expense: housing, food, transport, utilities,
             entertainment, health, education, other_expense
""")


def main() -> None:
    container = get_container()
    service = container.transaction_service

    print("\n  Finance Tracker  —  type 'help' for commands")
    cmd_balance(service)

    while True:
        try:
            raw = input("finance> ").strip()
            if not raw:
                continue
            parts = raw.split()
            cmd = parts[0].lower()
            args = parts[1:]

            if cmd == "add":
                cmd_add(service, args)
            elif cmd == "list":
                cmd_list(service, args)
            elif cmd == "balance":
                cmd_balance(service)
            elif cmd == "summary":
                cmd_summary(service, args)
            elif cmd == "categories":
                cmd_categories(service)
            elif cmd == "delete":
                cmd_delete(service, args)
            elif cmd == "import":
                cmd_import(container, args)
            elif cmd == "help":
                show_help()
            elif cmd in ("quit", "exit", "q"):
                print("\n  Goodbye.\n")
                break
            else:
                print(f"\n  Unknown command {cmd!r}. Type 'help'.\n")

        except KeyboardInterrupt:
            print("\n\n  Goodbye.\n")
            break
        except Exception as e:
            print(f"\n  Unexpected error: {e}\n")


if __name__ == "__main__":
    main()
```

---

## Tests

```python
# tests/conftest.py

import pytest
from finance_tracker.repositories.memory_repository import InMemoryTransactionRepository
from finance_tracker.services.transaction_service import TransactionService
from finance_tracker.services.import_service import CSVImportService
from finance_tracker.infrastructure.config import get_settings


@pytest.fixture
def repo():
    return InMemoryTransactionRepository()


@pytest.fixture
def service(repo):
    return TransactionService(repo)


@pytest.fixture
def import_service(service):
    return CSVImportService(service)


@pytest.fixture(autouse=True)
def clear_settings_cache():
    get_settings.cache_clear()
    yield
    get_settings.cache_clear()
```

```python
# tests/unit/test_transaction_service.py

import pytest
from decimal import Decimal
from datetime import date
from finance_tracker.domain.transaction import TransactionType, Category
from finance_tracker.domain.exceptions import (
    TransactionNotFoundError, InvalidTransactionError
)


class TestAddTransaction:

    def test_add_income(self, service):
        txn = service.add_transaction("2500.00", "salary", "Monthly salary")
        assert txn.amount == Decimal("2500.00")
        assert txn.transaction_type == TransactionType.INCOME
        assert txn.id > 0

    def test_add_expense(self, service):
        txn = service.add_transaction("45.50", "food", "Groceries")
        assert txn.transaction_type == TransactionType.EXPENSE
        assert txn.amount == Decimal("45.50")

    def test_defaults_to_today(self, service):
        txn = service.add_transaction("100", "food", "Lunch")
        assert txn.transaction_date == date.today()

    def test_custom_date(self, service):
        txn = service.add_transaction(
            "100", "food", "Lunch", transaction_date="2024-01-15"
        )
        assert txn.transaction_date == date(2024, 1, 15)

    def test_invalid_amount_raises(self, service):
        with pytest.raises(InvalidTransactionError, match="amount"):
            service.add_transaction("not-a-number", "food", "Test")

    def test_negative_amount_raises(self, service):
        with pytest.raises(InvalidTransactionError):
            service.add_transaction("-50", "food", "Test")

    def test_zero_amount_raises(self, service):
        with pytest.raises(InvalidTransactionError):
            service.add_transaction("0", "food", "Test")

    def test_invalid_category_raises(self, service):
        with pytest.raises(InvalidTransactionError, match="category"):
            service.add_transaction("100", "luxury", "Test")

    def test_empty_description_raises(self, service):
        with pytest.raises(InvalidTransactionError):
            service.add_transaction("100", "food", "")

    def test_strips_description(self, service):
        txn = service.add_transaction("100", "food", "  Groceries  ")
        assert txn.description == "Groceries"

    @pytest.mark.parametrize("category", [c.value for c in Category])
    def test_all_categories_accepted(self, service, category):
        txn = service.add_transaction("100", category, "Test transaction")
        assert txn.category.value == category


class TestDeleteTransaction:

    def test_delete_existing(self, service):
        txn = service.add_transaction("100", "food", "Test")
        service.delete_transaction(txn.id)
        with pytest.raises(TransactionNotFoundError):
            service.get_transaction(txn.id)

    def test_delete_nonexistent_raises(self, service):
        with pytest.raises(TransactionNotFoundError) as exc_info:
            service.delete_transaction(999)
        assert exc_info.value.transaction_id == 999


class TestGetBalance:

    def test_empty_balance(self, service):
        b = service.get_balance()
        assert b["balance"] == Decimal("0")
        assert b["transaction_count"] == 0

    def test_balance_calculation(self, service):
        service.add_transaction("3000", "salary", "Salary")
        service.add_transaction("1000", "freelance", "Project")
        service.add_transaction("800", "housing", "Rent")
        service.add_transaction("200", "food", "Groceries")

        b = service.get_balance()
        assert b["total_income"] == Decimal("4000")
        assert b["total_expenses"] == Decimal("1000")
        assert b["balance"] == Decimal("3000")


class TestMonthlySummary:

    def test_monthly_summary(self, service):
        service.add_transaction(
            "2500", "salary", "Salary", "2024-01-15"
        )
        service.add_transaction(
            "500", "food", "Groceries", "2024-01-20"
        )
        service.add_transaction(
            "1000", "housing", "Rent", "2024-01-01"
        )

        summary = service.get_monthly_summary(2024, 1)
        assert summary["income"] == Decimal("2500")
        assert summary["expenses"] == Decimal("1500")
        assert summary["net"] == Decimal("1000")
        assert summary["transaction_count"] == 3

    def test_excludes_other_months(self, service):
        service.add_transaction("1000", "salary", "Jan", "2024-01-15")
        service.add_transaction("2000", "salary", "Feb", "2024-02-15")

        jan = service.get_monthly_summary(2024, 1)
        assert jan["income"] == Decimal("1000")
        assert jan["transaction_count"] == 1
```

```python
# tests/unit/test_import_service.py

import pytest
from decimal import Decimal


VALID_CSV = """date,amount,category,description
2024-01-15,2500.00,salary,Monthly salary
2024-01-20,45.50,food,Groceries
2024-01-25,800.00,housing,Rent
"""

PARTIAL_FAILURE_CSV = """date,amount,category,description
2024-01-15,2500.00,salary,Valid row
2024-01-20,not-a-number,food,Invalid amount
2024-01-25,800.00,housing,Valid row 2
"""

MISSING_COLUMNS_CSV = """date,amount,description
2024-01-15,2500.00,Missing category column
"""


class TestCSVImport:

    def test_import_valid_csv(self, import_service):
        result = import_service.import_csv(VALID_CSV)
        assert result.imported == 3
        assert result.failed == 0
        assert result.success_rate == 100.0

    def test_partial_failure(self, import_service):
        result = import_service.import_csv(PARTIAL_FAILURE_CSV)
        assert result.imported == 2
        assert result.failed == 1
        assert len(result.errors) == 1
        assert result.errors[0]["row"] == 3

    def test_missing_columns_raises(self, import_service):
        from finance_tracker.domain.exceptions import ImportError as ImportFinanceError
        with pytest.raises(ImportFinanceError, match="Missing required columns"):
            import_service.import_csv(MISSING_COLUMNS_CSV)

    def test_imported_data_is_correct(self, import_service, service):
        import_service.import_csv(VALID_CSV)
        txns = service.list_transactions()
        assert len(txns) == 3
        amounts = sorted([t.amount for t in txns])
        assert amounts == [
            Decimal("45.50"), Decimal("800.00"), Decimal("2500.00")
        ]
```

```python
# tests/integration/test_api.py

import pytest
from fastapi.testclient import TestClient
from unittest.mock import patch
from finance_tracker.repositories.memory_repository import InMemoryTransactionRepository
from finance_tracker.services.transaction_service import TransactionService
from finance_tracker.services.import_service import CSVImportService
from finance_tracker.infrastructure.container import Container, get_container
from finance_tracker.api.app import create_app


@pytest.fixture
def test_container():
    repo = InMemoryTransactionRepository()
    service = TransactionService(repo)
    container = Container.__new__(Container)
    container._repository = repo
    container._transaction_service = service
    container._import_service = CSVImportService(service)
    return container


@pytest.fixture
def client(test_container):
    app = create_app()
    with patch(
        "finance_tracker.infrastructure.container.get_container",
        return_value=test_container
    ):
        with TestClient(app) as c:
            yield c


class TestTransactionAPI:

    def test_create_transaction(self, client):
        response = client.post("/api/v1/transactions", json={
            "amount": "100.00",
            "category": "food",
            "description": "Groceries",
        })
        assert response.status_code == 201
        data = response.json()
        assert data["amount"] == "100.00"
        assert data["category"] == "food"
        assert data["transaction_type"] == "expense"
        assert "id" in data

    def test_create_invalid_amount_fails(self, client):
        response = client.post("/api/v1/transactions", json={
            "amount": "-50",
            "category": "food",
            "description": "Test",
        })
        assert response.status_code == 422

    def test_create_invalid_category_fails(self, client):
        response = client.post("/api/v1/transactions", json={
            "amount": "100",
            "category": "luxury",
            "description": "Test",
        })
        assert response.status_code == 422

    def test_list_transactions(self, client):
        client.post("/api/v1/transactions", json={
            "amount": "2500", "category": "salary", "description": "Salary"
        })
        client.post("/api/v1/transactions", json={
            "amount": "50", "category": "food", "description": "Lunch"
        })
        response = client.get("/api/v1/transactions")
        assert response.status_code == 200
        assert len(response.json()) == 2

    def test_get_balance(self, client):
        client.post("/api/v1/transactions", json={
            "amount": "3000", "category": "salary", "description": "Salary"
        })
        client.post("/api/v1/transactions", json={
            "amount": "1000", "category": "housing", "description": "Rent"
        })
        response = client.get("/api/v1/transactions/balance")
        assert response.status_code == 200
        data = response.json()
        assert data["balance"] == "2000.00"

    def test_get_nonexistent_returns_404(self, client):
        response = client.get("/api/v1/transactions/99999")
        assert response.status_code == 404

    def test_delete_transaction(self, client):
        create = client.post("/api/v1/transactions", json={
            "amount": "50", "category": "food", "description": "Test"
        })
        txn_id = create.json()["id"]
        delete = client.delete(f"/api/v1/transactions/{txn_id}")
        assert delete.status_code == 204

        get = client.get(f"/api/v1/transactions/{txn_id}")
        assert get.status_code == 404
```

---

## Entry points

```python
# main.py

import sys
import os


def run_api():
    import uvicorn
    from finance_tracker.infrastructure.config import get_settings
    settings = get_settings()
    uvicorn.run(
        "finance_tracker.api.app:app",
        host=settings.api_host,
        port=settings.api_port,
        reload=settings.debug,
    )


def run_cli():
    from finance_tracker.cli.commands import main
    main()


if __name__ == "__main__":
    mode = sys.argv[1] if len(sys.argv) > 1 else "cli"
    if mode == "api":
        run_api()
    else:
        run_cli()
```

---

## CI/CD

```yaml
# .github/workflows/ci.yml

name: CI

on:
  push:
    branches: [main]
  pull_request:
    branches: [main]

jobs:
  quality:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-python@v5
        with:
          python-version: "3.11"
          cache: pip
      - run: pip install ruff mypy pydantic-settings
      - run: ruff check --no-fix src/ tests/
      - run: ruff format --check src/ tests/
      - run: mypy src/ --ignore-missing-imports

  test:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        python-version: ["3.11", "3.12"]
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-python@v5
        with:
          python-version: ${{ matrix.python-version }}
          cache: pip
      - run: pip install -e ".[dev]"
      - run: pytest --cov=src/finance_tracker --cov-report=xml --cov-fail-under=80
```

---

## Run it

```bash
# Install
pip install -e ".[dev]"

# Run tests
pytest tests/ -v

# Run tests with coverage
pytest tests/ --cov=src/finance_tracker --cov-report=term-missing

# Run CLI
python main.py cli

# Run API server
python main.py api
# → http://127.0.0.1:8000/docs

# Try the CLI
finance> add 3000 salary "Monthly salary" 2024-01-31
finance> add 800 housing "Rent" 2024-01-01
finance> add 200 food "Groceries" 2024-01-15
finance> add 50 transport "Train pass" 2024-01-10
finance> balance
finance> summary 2024 1
finance> categories
finance> list --type expense
finance> list --from 2024-01-01 --to 2024-01-31

# Import sample data
cat > sample.csv << EOF
date,amount,category,description
2024-02-01,3000.00,salary,February salary
2024-02-05,900.00,housing,February rent
2024-02-10,150.00,food,Weekly shop
2024-02-12,45.00,transport,Monthly pass
2024-02-15,60.00,entertainment,Cinema and dinner
EOF
finance> import sample.csv
```

---

## What this capstone demonstrates

Every concept from 30 days appears in this application:

|Day|Concept|Where|
|---|---|---|
|1-6|Variables, types, control flow, functions|Throughout|
|7|CLI project|`cli/commands.py`|
|8|File I/O|`import_service.py`, CSV handling|
|9|Error handling|Custom exceptions, service layer|
|10|Modules and packages|Full package structure|
|11|Comprehensions|Repository filtering|
|12-13|OOP, dataclasses|Domain objects, repositories|
|14|Week 2 project|Evolved into this|
|15|Decorators|`handle_domain_errors`|
|16|Generators|Repository iteration|
|17|APIs|FastAPI routes, schemas|
|18|Testing|Full test suite, fixtures, mocks|
|19|Concurrency|Async FastAPI handlers|
|20|Databases|SQLite repository|
|21|Week 3 project|Evolved into this|
|22|Type hints, Pydantic|Schemas, domain objects|
|23|Design patterns|Repository, factory, decorator|
|24|Performance|Indexed queries, Decimal for money|
|25|Packaging|`pyproject.toml`, entry points|
|26|FastAPI|Complete REST API|
|27|Data work|CSV import, aggregation queries|
|28|CI/CD|GitHub Actions, ruff, mypy|
|29|Architecture|Layered architecture, DI|
|30|Capstone|This|

---

## What comes next

Thirty days of fundamentals gives you the foundation. The next step is depth in one direction based on what you want to build.

**Web development:** Django for full-stack, FastAPI for APIs. Learn authentication (OAuth2, JWT), background tasks (Celery), caching (Redis), and deployment (Docker, cloud platforms).

**Data engineering:** pandas deeply, then PySpark for large-scale work. Learn Airflow for pipelines, dbt for transformations, and cloud data warehouses.

**Machine learning:** NumPy and pandas first, then scikit-learn, then PyTorch or TensorFlow depending on what you're building.

**DevOps and tooling:** Docker, Kubernetes, Terraform. Python is the scripting language of infrastructure.

**Systems programming:** asyncio deeply, then Cython or ctypes for performance-critical work, then potentially Rust for the pieces where Python is too slow.

The most important next step is to build something real that you care about. Every hour spent on a project you're invested in teaches more than ten hours of exercises. Take the architecture from Day 29, the tooling from Day 28, and the patterns from this course — and build something.

The exercises from all 30 days are waiting. They'll hit differently now that you've seen how everything connects.

[[Advanced]]