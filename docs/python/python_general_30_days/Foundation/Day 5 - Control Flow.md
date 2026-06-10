<<<<<<< HEAD
[[Foundation]]
=======

>>>>>>> origin/main
## Conditionals — if/elif/else

```python
age = 25

if age < 18:
    print("Minor")
elif age < 65:
    print("Adult")
else:
    print("Senior")
```

Python uses indentation to define blocks — not braces, not keywords. 4 spaces is the standard. The indentation _is_ the syntax. Get it wrong and your code either crashes or does the wrong thing silently.

**Comparison operators:**

```python
x = 10

print(x == 10)    # True  — equal to
print(x != 10)    # False — not equal to
print(x > 5)      # True  — greater than
print(x < 5)      # False — less than
print(x >= 10)    # True  — greater than or equal
print(x <= 10)    # True  — less than or equal

# Identity vs equality — critical distinction
a = [1, 2, 3]
b = [1, 2, 3]
c = a

print(a == b)     # True  — same values
print(a is b)     # False — different objects in memory
print(a is c)     # True  — same object
```

Use `==` to compare values. Use `is` only to check identity — most commonly `is None` and `is not None`.

**Logical operators:**

```python
age = 25
employed = True

# and — both must be True
if age >= 18 and employed:
    print("Eligible")

# or — at least one must be True
if age < 18 or age > 65:
    print("Special rate")

# not — inverts the boolean
if not employed:
    print("Unemployed")

# Chained comparisons — Pythonic, reads like math
score = 75
if 60 <= score < 90:
    print("B grade")

# Equivalent to:
if score >= 60 and score < 90:
    print("B grade")
```

**Truthiness in conditions — how Python actually evaluates:**

```python
name = ""
items = []
count = 0
data = None

# All of these are False
if name:    print("has name")
if items:   print("has items")
if count:   print("non-zero")
if data:    print("has data")

# Real usage — clean and Pythonic
users = ["Alice", "Bob"]
if users:
    print(f"Processing {len(users)} users")
else:
    print("No users to process")
```

**Ternary expression — one-line conditional:**

```python
age = 20
status = "adult" if age >= 18 else "minor"
print(status)    # adult

# Use for simple assignments — not for complex logic
# This is readable:
label = "even" if num % 2 == 0 else "odd"

# This is not — don't do this:
result = "a" if x > 0 else "b" if x < 0 else "c"
```

---

## For loops — iterating over sequences

```python
# Over a list
fruits = ["apple", "banana", "cherry"]
for fruit in fruits:
    print(fruit)

# Over a string
for char in "Python":
    print(char)

# Over a range
for i in range(5):        # 0, 1, 2, 3, 4
    print(i)

for i in range(2, 8):     # 2, 3, 4, 5, 6, 7
    print(i)

for i in range(0, 10, 2): # 0, 2, 4, 6, 8 — step of 2
    print(i)

for i in range(10, 0, -1): # 10, 9, 8 ... 1 — countdown
    print(i)
```

**enumerate — when you need index and value:**

```python
fruits = ["apple", "banana", "cherry"]

# Never do this:
for i in range(len(fruits)):
    print(i, fruits[i])

# Always do this:
for i, fruit in enumerate(fruits):
    print(i, fruit)

for i, fruit in enumerate(fruits, start=1):
    print(f"{i}. {fruit}")
```

**zip — iterate multiple sequences together:**

```python
names = ["Alice", "Bob", "Charlie"]
scores = [95, 87, 92]
grades = ["A", "B", "A"]

for name, score, grade in zip(names, scores, grades):
    print(f"{name}: {score} ({grade})")

# zip stops at the shortest sequence
# Use itertools.zip_longest to go to the end of the longest
from itertools import zip_longest
for a, b in zip_longest([1, 2, 3], [4, 5], fillvalue=0):
    print(a, b)
# 1 4
# 2 5
# 3 0
```

**Iterating over dicts:**

```python
person = {"name": "Alice", "age": 30, "city": "London"}

for key in person:               # keys by default
    print(key)

for value in person.values():
    print(value)

for key, value in person.items():  # what you'll use most
    print(f"{key}: {value}")
```

---

## While loops — when you don't know how many iterations

```python
# Basic while
count = 0
while count < 5:
    print(count)
    count += 1

# Input validation — classic while use case
# (In real code you'd use input(), but here's the pattern)
attempts = 0
max_attempts = 3
authenticated = False

while attempts < max_attempts and not authenticated:
    # password = input("Enter password: ")
    password = "wrong" if attempts < 2 else "correct"
    if password == "correct":
        authenticated = True
        print("Access granted")
    else:
        attempts += 1
        print(f"Wrong password. {max_attempts - attempts} attempts left")

# Infinite loop with break — used in servers, event loops, CLI menus
while True:
    command = "quit"   # simulating user input
    if command == "quit":
        print("Exiting")
        break
    print(f"Running: {command}")
```

---

## break, continue, pass — loop control

```python
# break — exit the loop entirely
numbers = [1, 3, 7, 2, 8, 4]
for num in numbers:
    if num % 2 == 0:
        print(f"First even: {num}")
        break    # stops at 2, never sees 8 or 4

# continue — skip this iteration, move to next
for num in range(10):
    if num % 2 == 0:
        continue    # skip even numbers
    print(num)      # prints 1, 3, 5, 7, 9

# pass — do nothing, used as a placeholder
for num in range(5):
    if num == 3:
        pass        # placeholder — code here later
    print(num)      # still prints everything, pass does nothing
```

**for/else and while/else — Python-specific, genuinely useful:**

```python
# The else block runs if the loop completed WITHOUT hitting a break
numbers = [1, 3, 5, 7, 9]
for num in numbers:
    if num % 2 == 0:
        print("Found an even number")
        break
else:
    print("No even numbers found")    # this runs — loop finished without break

# Real use case — searching
def find_user(users, target_id):
    for user in users:
        if user["id"] == target_id:
            print(f"Found: {user['name']}")
            break
    else:
        print("User not found")    # only runs if break was never hit
```

Most developers don't know `for/else` exists. It's cleaner than setting a flag variable to track whether something was found.

---

## Nested loops — and how to escape them

```python
# Basic nested loop
matrix = [[1, 2, 3], [4, 5, 6], [7, 8, 9]]
for row in matrix:
    for value in row:
        print(value, end=" ")
    print()    # newline after each row

# break only exits the innermost loop
for i in range(3):
    for j in range(3):
        if j == 1:
            break          # exits inner loop only
        print(f"{i},{j}")  # prints 0,0 / 1,0 / 2,0

# To break out of nested loops — use a flag or a function
# Flag approach:
found = False
for i in range(5):
    for j in range(5):
        if i * j > 6:
            found = True
            break
    if found:
        break

# Function approach — cleaner
def find_pair(limit):
    for i in range(limit):
        for j in range(limit):
            if i * j > 6:
                return i, j    # return exits the function entirely
    return None, None

i, j = find_pair(5)
print(i, j)    # 2 4
```

The function approach is almost always cleaner than a flag. If you find yourself writing nested loops with a flag variable, that's a signal to extract the inner logic into a function.

---

## Common patterns you'll write constantly

**Building a filtered list:**

```python
numbers = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]

evens = []
for n in numbers:
    if n % 2 == 0:
        evens.append(n)

print(evens)    # [2, 4, 6, 8, 10]
# Day 11 replaces this with list comprehensions — one line
```

**Running total:**

```python
prices = [10.99, 24.50, 3.75, 8.20]
total = 0
for price in prices:
    total += price
print(f"Total: ${total:.2f}")   # Total: $47.44
# Or just: sum(prices)
```

**Finding an item:**

```python
users = [
    {"id": 1, "name": "Alice"},
    {"id": 2, "name": "Bob"},
    {"id": 3, "name": "Charlie"},
]

target_id = 2
found_user = None
for user in users:
    if user["id"] == target_id:
        found_user = user
        break

if found_user:
    print(f"Found: {found_user['name']}")
else:
    print("Not found")
```

**Processing until a condition is met:**

```python
data = [4, 7, 2, 9, 1, 5, 8, 3]
threshold = 8
result = []

for value in data:
    if value >= threshold:
        break
    result.append(value)

print(result)    # [4, 7, 2]
```

---

## The mental model to carry forward

Control flow is just decision-making and repetition. Every program ever written is some combination of:

- "Do this if that condition is true"
- "Do this for each item in this collection"
- "Keep doing this until something changes"

The Python-specific things to internalize:

- Indentation is syntax — it's not style
- Truthiness means you rarely need `== True` or `== False` or `len(x) > 0`
- `enumerate` and `zip` replace index-based loops
- `for/else` replaces flag variables in search loops
- `break` inside a function is often better replaced by `return`

---

<<<<<<< HEAD
Day 6 is functions — where code stops being a script and starts being a program. Ready when you are.
=======
[[Foundation]]
>>>>>>> origin/main
