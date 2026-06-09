Let's make sure your environment is solid first, then we'll write code that actually does something useful.

### Step 1 — Verify your setup

Open your terminal and run these one by one:

bash

```bash
python --version        # Should say 3.11 or higher
# If that fails, try:
python3 --version
```

bash

```bash
pip --version           # Should print a version number
```

Tell me what you see. If anything is missing or broken, we fix it now before writing a single line of code.

---

### Step 2 — How Python actually runs your code

Before touching VS Code, understand this mental model:

```
You write .py file  →  Python interpreter reads it top to bottom  →  Executes each line
```

Python is an **interpreted** language. There's no compile step. When you run `python script.py`, Python reads and executes your file line by line, immediately. This is why errors tell you the exact line number — it ran fine up to that point, then hit a problem.

---

### Step 3 — Your first real script

Don't write `print("Hello World")`. That teaches you nothing. Write something that actually does work.

Create a file called `day1.py` and type this out (don't paste):

python

```python
# day1.py

name = "Alice"
age = 30
height = 1.75
is_employed = True

print("Name:", name)
print("Age:", age)
print("Height:", height)
print("Employed:", is_employed)
print("Type of name:", type(name))
print("Type of age:", type(age))
print("Type of height:", type(height))
print("Type of is_employed:", type(is_employed))
```

Run it:

bash

```bash
python day1.py
```

**What to notice:** Python automatically knows what type each variable is — you never declared it. This is called dynamic typing. `type()` is a built-in function that tells you what Python thinks something is.

---

### Step 4 — Variables and how Python actually stores them

python

```python
# Variables are just labels pointing to objects in memory

x = 10
y = x        # y now points to the SAME object as x
x = 20       # x now points to a NEW object
print(x)     # 20
print(y)     # still 10 — y didn't change because integers are immutable

# You can reassign a variable to a completely different type
x = "hello"  # totally valid in Python
print(x)     # hello
```

Add this to your `day1.py` and run it. Make sure you understand _why_ `y` stays `10`.

---

### Step 5 — String operations (the ones you'll use daily)

python

```python
first_name = "John"
last_name = "Doe"

# Concatenation
full_name = first_name + " " + last_name
print(full_name)

# f-strings — use these always, forget + for combining strings
greeting = f"Hello, {full_name}! You have {3 + 2} messages."
print(greeting)

# Useful string methods
sentence = "  Python is great  "
print(sentence.strip())           # removes whitespace from both ends
print(sentence.strip().upper())   # chaining methods
print("python".capitalize())      # Python
print("hello world".split())      # ['hello', 'world']
print(",".join(["a", "b", "c"]))  # a,b,c
print(len("hello"))               # 5
print("world" in "hello world")   # True
```

---

### Step 6 — Basic arithmetic and the one thing that trips everyone up

python

```python
print(10 + 3)    # 13  — addition
print(10 - 3)    # 7   — subtraction
print(10 * 3)    # 30  — multiplication
print(10 / 3)    # 3.3333... — division, ALWAYS returns float
print(10 // 3)   # 3   — floor division, drops the decimal
print(10 % 3)    # 1   — modulo, gives the remainder
print(10 ** 3)   # 1000 — exponentiation

# The one that bites beginners:
print(7 / 2)     # 3.5  (float)
print(7 // 2)    # 3    (integer — truncated, not rounded)
```

`//` and `%` are used constantly in real code — pagination, checking if a number is even/odd, splitting data into chunks.

---

### Step 7 — Your exercise

Write a new file called `calculator.py`. It should:

1. Store two numbers in variables
2. Print the result of all 6 operations above (including `//` and `%`)
3. Use an f-string for every print line, formatted like: `"10 + 3 = 13"`
4. Add a third variable for your name and print: `"John's calculator: 10 + 3 = 13"`

No hints beyond what's already on this page. Write it, run it, then paste your code here.

---

**One question while you work:** When you run `10 / 2` in Python, what type is the result — and why does that matter? Think about it before you answer.

[[Foundation]]