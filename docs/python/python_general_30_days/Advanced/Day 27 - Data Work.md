####  pandas and Data Processing

## What pandas actually is

pandas is a library for working with tabular data — rows and columns, like a spreadsheet or database table, but in memory and programmable. It's built on NumPy and designed for the kind of data manipulation that would take pages of raw Python loops.

```bash
pip install pandas numpy matplotlib
```

The two core data structures:

- `Series` — a one-dimensional labeled array (one column)
- `DataFrame` — a two-dimensional labeled table (rows and columns)

---

## Series — the building block

```python
import pandas as pd
import numpy as np

# Create a Series
scores = pd.Series([85, 92, 78, 95, 88], name="scores")
print(scores)
# 0    85
# 1    92
# 2    78
# 3    95
# 4    88

# With a custom index
scores = pd.Series(
    [85, 92, 78, 95, 88],
    index=["Alice", "Bob", "Charlie", "Diana", "Eve"],
    name="scores"
)

print(scores["Alice"])     # 85 — label-based access
print(scores[0])           # 85 — position-based access
print(scores[["Alice", "Diana"]])  # multiple labels

# Vectorized operations — no loops needed
print(scores + 5)          # add 5 to every score
print(scores * 1.1)        # multiply every score
print(scores > 90)         # boolean series
print(scores[scores > 90]) # filter: only scores above 90

# Descriptive statistics
print(scores.mean())       # 87.6
print(scores.median())     # 88.0
print(scores.std())        # 6.39
print(scores.describe())   # count, mean, std, min, 25%, 50%, 75%, max
```

---

## DataFrame — the main event

```python
import pandas as pd

# Create from a list of dicts — most common in practice
data = [
    {"name": "Alice", "age": 30, "city": "London", "salary": 75000},
    {"name": "Bob", "age": 25, "city": "Manchester", "salary": 55000},
    {"name": "Charlie", "age": 35, "city": "London", "salary": 90000},
    {"name": "Diana", "age": 28, "city": "Bristol", "salary": 65000},
    {"name": "Eve", "age": 32, "city": "London", "salary": 80000},
]

df = pd.DataFrame(data)
print(df)
#       name  age        city  salary
# 0    Alice   30      London   75000
# 1      Bob   25  Manchester   55000
# 2  Charlie   35      London   90000
# 3    Diana   28     Bristol   65000
# 4      Eve   32      London   80000

# Basic inspection
print(df.shape)        # (5, 4) — rows, columns
print(df.dtypes)       # data type of each column
print(df.columns)      # Index(['name', 'age', 'city', 'salary'])
print(df.head(3))      # first 3 rows
print(df.tail(2))      # last 2 rows
print(df.info())       # shape, dtypes, memory usage, null counts
print(df.describe())   # statistics for numeric columns
```

---

## Selecting data — the core operations

```python
# Single column → Series
ages = df["age"]
print(ages)

# Multiple columns → DataFrame
subset = df[["name", "salary"]]

# Rows by label — df.loc[row_label, column_label]
df_indexed = df.set_index("name")   # set name as the index
alice = df_indexed.loc["Alice"]     # one row
london_people = df_indexed.loc[["Alice", "Charlie", "Eve"]]

# Rows by position — df.iloc[row_position, col_position]
first_row = df.iloc[0]             # first row
first_col = df.iloc[:, 0]          # first column, all rows
block = df.iloc[1:3, 1:3]          # rows 1-2, columns 1-2

# Boolean indexing — the most useful
london = df[df["city"] == "London"]
high_earners = df[df["salary"] > 70000]
london_high = df[(df["city"] == "London") & (df["salary"] > 70000)]

# isin — match against multiple values
major_cities = df[df["city"].isin(["London", "Manchester"])]

# String operations
df[df["name"].str.startswith("A")]
df[df["name"].str.contains("li", case=False)]
df[df["name"].str.len() > 4]
```

---

## Loading real data

```python
# CSV — the most common format
df = pd.read_csv("data.csv")
df = pd.read_csv("data.csv", index_col=0)           # first column as index
df = pd.read_csv("data.csv", parse_dates=["date"])  # parse date column
df = pd.read_csv("data.csv", nrows=1000)            # only first 1000 rows
df = pd.read_csv("data.csv", usecols=["name", "salary"])  # specific columns

# JSON
df = pd.read_json("data.json")
df = pd.read_json("https://jsonplaceholder.typicode.com/users")  # URL works too

# Excel
df = pd.read_excel("data.xlsx", sheet_name="Sheet1")

# From a database
import sqlite3
conn = sqlite3.connect("tasks.db")
df = pd.read_sql("SELECT * FROM tasks WHERE done = 0", conn)
conn.close()

# Saving
df.to_csv("output.csv", index=False)       # index=False: don't write row numbers
df.to_json("output.json", orient="records")
df.to_excel("output.xlsx", index=False)
df.to_sql("table_name", conn, if_exists="replace", index=False)
```

---

## Cleaning data — the real work

Real data is messy. Cleaning is 80% of data work.

```python
import pandas as pd
import numpy as np

# Simulate messy data
raw = pd.DataFrame({
    "name": ["Alice", "  Bob  ", "CHARLIE", None, "Eve"],
    "age": [30, 25, "thirty-five", 28, 32],
    "salary": [75000, 55000, None, 65000, 80000],
    "city": ["London", "manchester", "London", "Bristol", "london"],
    "email": ["alice@example.com", "bob@example.com", "invalid-email", "diana@example.com", "eve@example.com"],
    "joined": ["2020-01-15", "2019-06-30", "2021-03-15", "2020-11-01", "2018-09-20"],
})


# ── Missing values ───────────────────────────────────────────────

print(raw.isnull().sum())          # count nulls per column
print(raw.isnull().sum() / len(raw) * 100)  # percentage missing

# Drop rows with any null
df_clean = raw.dropna()

# Drop rows where specific columns are null
df_clean = raw.dropna(subset=["name", "salary"])

# Fill nulls
raw["salary"] = raw["salary"].fillna(raw["salary"].median())
raw["city"] = raw["city"].fillna("Unknown")

# Forward fill — useful for time series
raw["salary"] = raw["salary"].ffill()


# ── Data type conversion ─────────────────────────────────────────

df = raw.copy()

# Fix age — some are strings
def parse_age(val):
    try:
        return int(val)
    except (ValueError, TypeError):
        return np.nan

df["age"] = df["age"].apply(parse_age)
df["age"] = pd.to_numeric(df["age"], errors="coerce")   # NaN for unparseable

# Parse dates
df["joined"] = pd.to_datetime(df["joined"])
print(df["joined"].dt.year)     # extract year
print(df["joined"].dt.month)    # extract month


# ── String cleaning ──────────────────────────────────────────────

df["name"] = df["name"].str.strip()      # remove whitespace
df["name"] = df["name"].str.title()      # Title Case
df["city"] = df["city"].str.strip().str.title()

# Remove special characters
df["name"] = df["name"].str.replace(r"[^a-zA-Z\s]", "", regex=True)


# ── Deduplication ────────────────────────────────────────────────

print(df.duplicated().sum())              # count duplicates
df = df.drop_duplicates()                 # remove exact duplicates
df = df.drop_duplicates(subset=["email"]) # duplicates in specific column


# ── Outlier detection ────────────────────────────────────────────

Q1 = df["salary"].quantile(0.25)
Q3 = df["salary"].quantile(0.75)
IQR = Q3 - Q1
outliers = df[(df["salary"] < Q1 - 1.5 * IQR) | (df["salary"] > Q3 + 1.5 * IQR)]
df_clean = df[~df.index.isin(outliers.index)]    # remove outliers


# ── Validation ───────────────────────────────────────────────────

import re

def is_valid_email(email):
    if pd.isna(email):
        return False
    return bool(re.match(r"^[\w.+-]+@[\w-]+\.[a-z]{2,}$", str(email)))

df["email_valid"] = df["email"].apply(is_valid_email)
invalid_emails = df[~df["email_valid"]]
print(f"Invalid emails: {len(invalid_emails)}")


# ── Complete cleaning pipeline ────────────────────────────────────

def clean_dataframe(raw_df):
    df = raw_df.copy()

    # String cleaning
    df["name"] = df["name"].str.strip().str.title()
    df["city"] = df["city"].str.strip().str.title()
    df["email"] = df["email"].str.strip().str.lower()

    # Type conversion
    df["age"] = pd.to_numeric(df["age"], errors="coerce")
    df["salary"] = pd.to_numeric(df["salary"], errors="coerce")
    df["joined"] = pd.to_datetime(df["joined"], errors="coerce")

    # Fill missing values
    df["salary"] = df["salary"].fillna(df["salary"].median())
    df["age"] = df["age"].fillna(df["age"].median()).astype(int)

    # Remove duplicates and invalid rows
    df = df.drop_duplicates(subset=["email"])
    df = df.dropna(subset=["name"])

    return df

cleaned = clean_dataframe(raw)
```

---

## Transforming data — adding, changing, grouping

```python
import pandas as pd

df = pd.DataFrame({
    "name": ["Alice", "Bob", "Charlie", "Diana", "Eve", "Frank"],
    "dept": ["Engineering", "Marketing", "Engineering", "Marketing", "Engineering", "HR"],
    "salary": [90000, 60000, 85000, 65000, 95000, 55000],
    "years": [5, 3, 7, 4, 6, 2],
    "rating": [4.5, 3.8, 4.2, 4.0, 4.8, 3.5],
})


# ── Adding columns ───────────────────────────────────────────────

# Computed column
df["salary_per_year"] = df["salary"] / df["years"]

# Conditional column
df["level"] = df["salary"].apply(
    lambda s: "senior" if s >= 85000 else "mid" if s >= 65000 else "junior"
)

# Using np.where — faster than apply for simple conditions
import numpy as np
df["high_performer"] = np.where(df["rating"] >= 4.5, True, False)

# pd.cut — bin continuous values into categories
df["salary_band"] = pd.cut(
    df["salary"],
    bins=[0, 60000, 75000, 90000, float("inf")],
    labels=["Band 1", "Band 2", "Band 3", "Band 4"]
)


# ── apply — row or column operations ─────────────────────────────

# Apply to a column (Series)
df["name_length"] = df["name"].apply(len)

# Apply to each row (axis=1)
def performance_score(row):
    return round(row["rating"] * (row["years"] / 5), 2)

df["perf_score"] = df.apply(performance_score, axis=1)


# ── GroupBy — the most powerful operation ────────────────────────

# Group by department
by_dept = df.groupby("dept")

# Aggregate functions
print(by_dept["salary"].mean())
print(by_dept["salary"].agg(["mean", "min", "max", "count"]))

# Multiple columns
summary = df.groupby("dept").agg(
    avg_salary=("salary", "mean"),
    max_salary=("salary", "max"),
    headcount=("name", "count"),
    avg_rating=("rating", "mean"),
    avg_years=("years", "mean"),
).round(2)
print(summary)

# GroupBy with transform — add group statistics back to original df
df["dept_avg_salary"] = df.groupby("dept")["salary"].transform("mean")
df["vs_dept_avg"] = df["salary"] - df["dept_avg_salary"]

# Filtering groups
# Keep only departments with more than 2 people
df_filtered = df.groupby("dept").filter(lambda x: len(x) > 2)


# ── Sorting ──────────────────────────────────────────────────────

df.sort_values("salary", ascending=False)
df.sort_values(["dept", "salary"], ascending=[True, False])


# ── Merging and joining ───────────────────────────────────────────

dept_info = pd.DataFrame({
    "dept": ["Engineering", "Marketing", "HR"],
    "budget": [500000, 200000, 150000],
    "location": ["London", "Manchester", "Bristol"],
})

# Merge — like SQL JOIN
merged = df.merge(dept_info, on="dept", how="left")
# how="left"  → keep all rows from left df, match from right where possible
# how="inner" → only rows that match in both
# how="outer" → all rows from both, NaN where no match

# Concatenate — stack DataFrames
df_2023 = pd.DataFrame({"year": [2023], "revenue": [1_000_000]})
df_2024 = pd.DataFrame({"year": [2024], "revenue": [1_200_000]})
combined = pd.concat([df_2023, df_2024], ignore_index=True)
```

---

## Pivot tables — reshaping data

```python
import pandas as pd

sales = pd.DataFrame({
    "region": ["North", "South", "North", "South", "North", "South"] * 2,
    "product": ["A", "A", "B", "B", "C", "C"] * 2,
    "quarter": ["Q1"] * 6 + ["Q2"] * 6,
    "revenue": [10000, 12000, 8000, 9000, 15000, 11000,
                11000, 13000, 7000, 10000, 16000, 12000],
})

# Pivot table — aggregate values across two dimensions
pivot = sales.pivot_table(
    values="revenue",
    index="region",
    columns="quarter",
    aggfunc="sum",
    margins=True,    # add totals row/column
)
print(pivot)

# Melt — opposite of pivot, wide to long format
wide = pd.DataFrame({
    "name": ["Alice", "Bob"],
    "q1_sales": [100, 200],
    "q2_sales": [150, 180],
    "q3_sales": [120, 210],
})

long = wide.melt(
    id_vars=["name"],
    value_vars=["q1_sales", "q2_sales", "q3_sales"],
    var_name="quarter",
    value_name="sales"
)
print(long)
#     name     quarter  sales
# 0  Alice    q1_sales    100
# 1    Bob    q1_sales    200
# 2  Alice    q2_sales    150
# ...
```

---

## Time series — working with dates

```python
import pandas as pd
import numpy as np

# Generate date range
dates = pd.date_range("2024-01-01", periods=365, freq="D")
df = pd.DataFrame({
    "date": dates,
    "revenue": np.random.normal(10000, 2000, 365).round(2),
    "users": np.random.randint(500, 2000, 365),
})
df = df.set_index("date")

# Resample — aggregate by time period
monthly = df.resample("ME").agg({       # ME = month end
    "revenue": "sum",
    "users": "mean",
})
weekly = df.resample("W").sum()
quarterly = df.resample("QE").mean()

# Rolling windows — moving averages
df["revenue_7d"] = df["revenue"].rolling(window=7).mean()
df["revenue_30d"] = df["revenue"].rolling(window=30).mean()

# Date filtering
jan = df["2024-01"]              # entire January
q1 = df["2024-01":"2024-03"]     # Q1
after_june = df["2024-06-01":]   # from June onwards

# Date components
df["month"] = df.index.month
df["day_of_week"] = df.index.day_name()
df["is_weekend"] = df.index.dayofweek >= 5
```

---

## A complete data analysis pipeline

```python
import pandas as pd
import numpy as np
from pathlib import Path


def analyze_tasks(db_path="tasks.db"):
    """
    Load task data from SQLite and produce an analysis report.
    Demonstrates a complete pandas workflow.
    """
    import sqlite3

    # 1. Load
    conn = sqlite3.connect(db_path)
    df = pd.read_sql("""
        SELECT id, title, priority, done, created_at
        FROM tasks
    """, conn, parse_dates=["created_at"])
    conn.close()

    if df.empty:
        print("No tasks found.")
        return

    # 2. Clean and enrich
    df["priority"] = df["priority"].str.lower().str.strip()
    df["done"] = df["done"].astype(bool)
    df["created_date"] = df["created_at"].dt.date
    df["day_of_week"] = df["created_at"].dt.day_name()
    df["week"] = df["created_at"].dt.isocalendar().week

    # 3. Validate
    valid_priorities = {"low", "medium", "high"}
    invalid = df[~df["priority"].isin(valid_priorities)]
    if not invalid.empty:
        print(f"Warning: {len(invalid)} tasks with invalid priority")

    # 4. Analyze
    print("\n=== TASK ANALYSIS REPORT ===\n")

    # Overall stats
    total = len(df)
    done = df["done"].sum()
    completion_rate = done / total * 100
    print(f"Total tasks:      {total}")
    print(f"Completed:        {done} ({completion_rate:.1f}%)")
    print(f"Pending:          {total - done}")

    # By priority
    print("\n--- By Priority ---")
    priority_stats = df.groupby("priority").agg(
        total=("id", "count"),
        completed=("done", "sum"),
    )
    priority_stats["completion_rate"] = (
        priority_stats["completed"] / priority_stats["total"] * 100
    ).round(1)
    print(priority_stats.to_string())

    # Daily creation trend
    print("\n--- Tasks Created Per Day of Week ---")
    day_counts = df["day_of_week"].value_counts()
    day_order = ["Monday","Tuesday","Wednesday","Thursday","Friday","Saturday","Sunday"]
    day_counts = day_counts.reindex(day_order).fillna(0).astype(int)
    for day, count in day_counts.items():
        bar = "█" * (count // max(1, max(day_counts) // 20))
        print(f"  {day:<12} {count:>4}  {bar}")

    # Backlog by priority
    print("\n--- Pending Backlog ---")
    backlog = df[~df["done"]].groupby("priority")["id"].count()
    order = ["high", "medium", "low"]
    for p in order:
        count = backlog.get(p, 0)
        print(f"  {p.upper():<8} {count} tasks pending")

    return df


df = analyze_tasks()
```

---

## Visualization — quick charts from pandas

```python
import pandas as pd
import matplotlib.pyplot as plt

df = pd.DataFrame({
    "dept": ["Engineering", "Marketing", "HR", "Sales", "Finance"],
    "headcount": [45, 20, 10, 30, 15],
    "avg_salary": [95000, 65000, 55000, 70000, 80000],
})

# Bar chart
df.plot.bar(x="dept", y="headcount", title="Headcount by Department")
plt.tight_layout()
plt.savefig("headcount.png", dpi=150)

# Horizontal bar — better for many categories
df.sort_values("avg_salary").plot.barh(
    x="dept", y="avg_salary",
    title="Average Salary by Department"
)
plt.tight_layout()
plt.savefig("salaries.png", dpi=150)

# Scatter
df.plot.scatter(
    x="headcount", y="avg_salary",
    title="Headcount vs Salary"
)
plt.tight_layout()
plt.savefig("scatter.png", dpi=150)

# Time series line
dates = pd.date_range("2024-01", periods=12, freq="ME")
monthly = pd.DataFrame({
    "date": dates,
    "revenue": [80, 85, 90, 88, 92, 95, 100, 98, 105, 110, 108, 115],
})
monthly.set_index("date").plot.line(title="Monthly Revenue 2024")
plt.tight_layout()
plt.savefig("revenue.png", dpi=150)

plt.show()
```

---

## Performance with large datasets

```python
import pandas as pd
import numpy as np

# Reading large CSVs efficiently
df = pd.read_csv(
    "large_file.csv",
    usecols=["id", "date", "amount"],   # only load needed columns
    dtype={"id": np.int32, "amount": np.float32},  # smaller dtypes
    parse_dates=["date"],
    chunksize=None    # set to 10000 to process in chunks
)

# Process in chunks — constant memory regardless of file size
def process_large_csv(filepath, chunk_size=10_000):
    results = []
    for chunk in pd.read_csv(filepath, chunksize=chunk_size):
        # process each chunk
        summary = chunk.groupby("category")["amount"].sum()
        results.append(summary)
    return pd.concat(results).groupby(level=0).sum()

# Memory optimization — check and reduce dtypes
def optimize_dtypes(df):
    for col in df.select_dtypes(include=["int64"]).columns:
        df[col] = pd.to_numeric(df[col], downcast="integer")
    for col in df.select_dtypes(include=["float64"]).columns:
        df[col] = pd.to_numeric(df[col], downcast="float")
    return df

print(f"Before: {df.memory_usage(deep=True).sum() / 1024**2:.1f} MB")
df = optimize_dtypes(df)
print(f"After:  {df.memory_usage(deep=True).sum() / 1024**2:.1f} MB")
```

---

## The mental model to carry forward

pandas is SQL you can write in Python. Most pandas operations have a direct SQL equivalent:

```
SQL                          pandas
─────────────────────────────────────────────────────────────
SELECT col1, col2            df[["col1", "col2"]]
WHERE condition              df[df["col"] > value]
ORDER BY col DESC            df.sort_values("col", ascending=False)
GROUP BY col                 df.groupby("col")
HAVING COUNT(*) > 2          .filter(lambda x: len(x) > 2)
JOIN                         df.merge(other, on="col")
UNION ALL                    pd.concat([df1, df2])
COUNT(*), SUM, AVG           .agg(["count", "sum", "mean"])
```

**The data analysis workflow:**

- Load — `pd.read_csv`, `pd.read_sql`, `pd.read_json`
- Inspect — `.shape`, `.dtypes`, `.info()`, `.describe()`, `.head()`
- Clean — handle nulls, fix types, strip strings, remove duplicates
- Enrich — add computed columns, merge reference data
- Analyze — groupby, aggregate, pivot
- Visualize — `.plot()`, matplotlib
- Export — `to_csv`, `to_sql`, `to_json`

The rule that saves hours: **never loop over DataFrame rows**. If you find yourself writing `for index, row in df.iterrows()`, stop — there is almost always a vectorized pandas operation that does the same thing 100x faster.

---

Day 28 is tooling and CI — ruff, pre-commit, GitHub Actions, and the automation that makes a professional Python project run itself. Ready when you are.

[[Advanced]]