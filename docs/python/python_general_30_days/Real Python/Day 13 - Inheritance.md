#### Inheritance, super(), Dataclasses & Advanced OOP

## What inheritance actually solves

Inheritance is not about code reuse for its own sake. It's about modeling a genuine "is-a" relationship — when one thing genuinely is a more specific version of another thing.

```python
# A SavingsAccount IS-A BankAccount
# A Manager IS-A Employee
# A FileNotFoundError IS-A OSError

# If the relationship is "has-a" or "uses-a", don't use inheritance
# A Car HAS-A Engine — use composition, not inheritance
# A TaskManager USES-A FileSystem — use composition
```

Misusing inheritance is one of the most common OOP mistakes. The test: can you say "X is a Y" and mean it without stretching the truth?

---

## Basic inheritance

```python
class Animal:
    def __init__(self, name, species):
        self.name = name
        self.species = species

    def eat(self):
        return f"{self.name} is eating"

    def sleep(self):
        return f"{self.name} is sleeping"

    def __repr__(self):
        return f"{self.__class__.__name__}(name={self.name!r})"


class Dog(Animal):
    def __init__(self, name, breed):
        super().__init__(name, species="Canis lupus familiaris")
        self.breed = breed      # Dog-specific attribute

    def bark(self):             # Dog-specific method
        return f"{self.name} says: Woof!"

    def eat(self):              # Override parent method
        return f"{self.name} wolfs down the food"


class Cat(Animal):
    def __init__(self, name, indoor=True):
        super().__init__(name, species="Felis catus")
        self.indoor = indoor

    def purr(self):
        return f"{self.name} purrs..."

    def eat(self):
        return f"{self.name} delicately nibbles the food"


dog = Dog("Rex", "German Shepherd")
cat = Cat("Whiskers")

print(dog.eat())      # Rex wolfs down the food — overridden method
print(dog.sleep())    # Rex is sleeping — inherited from Animal
print(dog.bark())     # Rex says: Woof! — Dog-specific
print(cat.eat())      # Whiskers delicately nibbles the food

# isinstance checks the full hierarchy
print(isinstance(dog, Dog))      # True
print(isinstance(dog, Animal))   # True — Dog IS-A Animal
print(isinstance(dog, Cat))      # False

# issubclass checks the class hierarchy
print(issubclass(Dog, Animal))   # True
print(issubclass(Cat, Animal))   # True
print(issubclass(Dog, Cat))      # False
```

---

## super() — calling the parent

`super()` returns a proxy object that delegates method calls to the parent class. It's not just for `__init__` — you can use it in any method.

```python
class Vehicle:
    def __init__(self, make, model, year):
        self.make = make
        self.model = model
        self.year = year
        self.running = False

    def start(self):
        self.running = True
        return f"{self.make} {self.model} started"

    def stop(self):
        self.running = False
        return f"{self.make} {self.model} stopped"

    def __str__(self):
        return f"{self.year} {self.make} {self.model}"


class ElectricVehicle(Vehicle):
    def __init__(self, make, model, year, battery_kwh):
        super().__init__(make, model, year)   # initialize parent first
        self.battery_kwh = battery_kwh
        self.charge_level = 100

    def start(self):
        if self.charge_level == 0:
            return f"{self.make} {self.model} cannot start — battery empty"
        result = super().start()    # call parent's start(), then add EV behavior
        return f"{result} silently"

    def charge(self, amount):
        self.charge_level = min(100, self.charge_level + amount)
        return f"Charged to {self.charge_level}%"

    def __str__(self):
        base = super().__str__()    # reuse parent's __str__
        return f"{base} (Electric, {self.battery_kwh}kWh)"


ev = ElectricVehicle("Tesla", "Model 3", 2023, 75)
print(ev.start())    # Tesla Model 3 started silently
print(ev.stop())     # Tesla Model 3 stopped — inherited
print(str(ev))       # 2023 Tesla Model 3 (Electric, 75kWh)
```

Always call `super().__init__()` as the first thing in a subclass `__init__`. Always initialize the parent before adding subclass-specific state.

---

## Abstract classes — enforcing the interface

An abstract class defines what subclasses must implement. You can't instantiate it directly.

```python
from abc import ABC, abstractmethod

class Shape(ABC):
    """Abstract base class — cannot be instantiated directly."""

    @abstractmethod
    def area(self):
        """Every Shape must implement area()."""
        pass

    @abstractmethod
    def perimeter(self):
        """Every Shape must implement perimeter()."""
        pass

    def describe(self):
        """Concrete method — shared by all shapes."""
        return f"{self.__class__.__name__}: area={self.area():.2f}, perimeter={self.perimeter():.2f}"


class Rectangle(Shape):
    def __init__(self, width, height):
        self.width = width
        self.height = height

    def area(self):
        return self.width * self.height

    def perimeter(self):
        return 2 * (self.width + self.height)


class Circle(Shape):
    def __init__(self, radius):
        self.radius = radius

    def area(self):
        import math
        return math.pi * self.radius ** 2

    def perimeter(self):
        import math
        return 2 * math.pi * self.radius


# Shape()       # TypeError — can't instantiate abstract class
rect = Rectangle(4, 6)
circle = Circle(5)

print(rect.describe())      # Rectangle: area=24.00, perimeter=20.00
print(circle.describe())    # Circle: area=78.54, perimeter=31.42

# Polymorphism — treat all shapes the same way
shapes = [Rectangle(3, 4), Circle(2), Rectangle(10, 2)]
total_area = sum(s.area() for s in shapes)
print(f"Total area: {total_area:.2f}")
```

Abstract classes define contracts. If a subclass doesn't implement all abstract methods, Python raises `TypeError` when you try to instantiate it. This catches mistakes at object creation, not buried in runtime behavior.

---

## Mixins — composing behavior without deep hierarchies

A mixin is a class that provides methods to other classes without being a standalone class itself. It solves the problem of sharing behavior across classes that don't share a natural inheritance relationship.

```python
class TimestampMixin:
    """Adds created_at and updated_at tracking to any class."""

    def __init__(self, *args, **kwargs):
        from datetime import datetime
        super().__init__(*args, **kwargs)
        self.created_at = datetime.now()
        self.updated_at = datetime.now()

    def touch(self):
        from datetime import datetime
        self.updated_at = datetime.now()


class SerializeMixin:
    """Adds JSON serialization to any class."""

    def to_dict(self):
        return {
            k: v for k, v in self.__dict__.items()
            if not k.startswith("_")
        }

    def to_json(self):
        import json
        from datetime import datetime

        def default(obj):
            if isinstance(obj, datetime):
                return obj.isoformat()
            raise TypeError(f"Not serializable: {type(obj)}")

        return json.dumps(self.to_dict(), default=default, indent=2)


class ValidationMixin:
    """Adds field validation."""

    def validate(self):
        """Override in subclass to add validation logic."""
        return True


# Compose mixins into a real class
class User(TimestampMixin, SerializeMixin, ValidationMixin):
    def __init__(self, name, email):
        super().__init__()
        self.name = name
        self.email = email

    def validate(self):
        if not self.name:
            raise ValueError("Name required")
        if "@" not in self.email:
            raise ValueError("Invalid email")
        return True


user = User("Alice", "alice@example.com")
user.validate()
print(user.to_json())
# {
#   "created_at": "2024-01-15T14:30:00",
#   "updated_at": "2024-01-15T14:30:00",
#   "name": "Alice",
#   "email": "alice@example.com"
# }
```

Mixins should be small, focused, and named with "Mixin" suffix by convention. They're powerful but overusing them creates complex MRO (Method Resolution Order) chains that are hard to debug.

---

## Dataclasses — eliminating boilerplate

Writing `__init__`, `__repr__`, and `__eq__` for every data-holding class is tedious. `@dataclass` generates them automatically.

```python
from dataclasses import dataclass, field
from typing import Optional
from datetime import datetime


@dataclass
class Task:
    # Fields with types — __init__ is generated automatically
    id: int
    title: str
    priority: str = "medium"
    done: bool = False
    created_at: datetime = field(default_factory=datetime.now)
    tags: list = field(default_factory=list)    # mutable default — must use field()

    # __repr__ is generated: Task(id=1, title='...', priority='medium', ...)
    # __eq__ is generated: compares all fields


task1 = Task(id=1, title="Fix bug", priority="high")
task2 = Task(id=1, title="Fix bug", priority="high")
task3 = Task(id=2, title="Write tests")

print(task1)          # Task(id=1, title='Fix bug', priority='high', done=False, ...)
print(task1 == task2) # True — __eq__ compares fields
print(task1 == task3) # False

# You can still add methods
@dataclass
class Point:
    x: float
    y: float

    def distance_to(self, other: "Point") -> float:
        return ((self.x - other.x)**2 + (self.y - other.y)**2) ** 0.5

    def __add__(self, other: "Point") -> "Point":
        return Point(self.x + other.x, self.y + other.y)


p1 = Point(0, 0)
p2 = Point(3, 4)
print(p1.distance_to(p2))    # 5.0
print(p1 + p2)               # Point(x=3, y=4)
```

**Dataclass options:**

```python
from dataclasses import dataclass

@dataclass(frozen=True)   # immutable — raises FrozenInstanceError on assignment
class Coordinate:
    lat: float
    lon: float

coord = Coordinate(51.5, -0.12)
# coord.lat = 52.0    # FrozenInstanceError

# frozen=True also makes it hashable — can be used as a dict key
locations = {coord: "London"}


@dataclass(order=True)    # generates __lt__, __le__, __gt__, __ge__
class Version:
    major: int
    minor: int
    patch: int

v1 = Version(1, 2, 3)
v2 = Version(1, 3, 0)
print(v1 < v2)    # True — compares field by field
print(sorted([Version(2,0,0), Version(1,9,0), Version(1,2,3)]))
```

**When to use dataclass vs regular class:**

- Use `@dataclass` when the class primarily holds data with little behavior
- Use regular class when behavior dominates or you need fine control over `__init__`
- Use `@dataclass(frozen=True)` for value objects that should never change

---

## The Method Resolution Order — how Python handles multiple inheritance

```python
class A:
    def hello(self):
        return "Hello from A"

class B(A):
    def hello(self):
        return "Hello from B"

class C(A):
    def hello(self):
        return "Hello from C"

class D(B, C):
    pass

d = D()
print(d.hello())     # Hello from B

# Python uses C3 linearization — check the MRO
print(D.__mro__)
# (<class 'D'>, <class 'B'>, <class 'C'>, <class 'A'>, <class 'object'>)
# Python searches left to right, depth first, respecting the ordering
```

When `super()` is called, it follows the MRO — not "the parent class" but "the next class in the MRO." This is why mixins work correctly even in complex hierarchies.

---

## Composition over inheritance — the real-world preference

Deep inheritance hierarchies are fragile. In practice, experienced Python developers prefer composition — objects that contain other objects.

```python
# Inheritance approach — fragile, tightly coupled
class Animal:
    def breathe(self): ...

class FlyingAnimal(Animal):
    def fly(self): ...

class SwimmingAnimal(Animal):
    def swim(self): ...

# What about a duck? It flies AND swims
class Duck(FlyingAnimal, SwimmingAnimal):    # multiple inheritance gets messy
    pass


# Composition approach — flexible, loosely coupled
class FlyingBehavior:
    def fly(self):
        return "Flying with wings"

class SwimmingBehavior:
    def swim(self):
        return "Swimming with legs"

class WalkingBehavior:
    def walk(self):
        return "Walking on legs"

class Duck:
    def __init__(self):
        self.flying = FlyingBehavior()
        self.swimming = SwimmingBehavior()
        self.walking = WalkingBehavior()

    def fly(self):
        return self.flying.fly()

    def swim(self):
        return self.swimming.swim()


duck = Duck()
print(duck.fly())     # Flying with wings
print(duck.swim())    # Swimming with legs
```

The rule of thumb in production Python: **favor composition, use inheritance only for genuine is-a relationships, use mixins for small reusable behaviors.**

---

## Slots — memory optimization for classes with many instances

```python
class Point:
    __slots__ = ("x", "y")    # tells Python exactly what attributes exist

    def __init__(self, x, y):
        self.x = x
        self.y = y


# __slots__ prevents adding arbitrary attributes
p = Point(1, 2)
p.z = 3    # AttributeError — z is not in __slots__

# Why bother? Memory and speed
# Without __slots__: each instance has a __dict__ (a hash map) — ~400 bytes
# With __slots__: attributes stored in fixed array — ~56 bytes
# 7x memory reduction — matters when creating millions of instances
```

Use `__slots__` when you're creating massive numbers of instances (data processing, game entities, simulations) and know the attributes won't change.

---

## Putting it all together — a real class hierarchy

```python
from abc import ABC, abstractmethod
from dataclasses import dataclass, field
from datetime import datetime


class Notification(ABC):
    """Abstract base — all notifications must implement send()."""

    @abstractmethod
    def send(self, recipient: str, message: str) -> bool:
        pass

    def send_batch(self, recipients: list, message: str) -> dict:
        return {r: self.send(r, message) for r in recipients}


@dataclass
class EmailNotification(Notification):
    sender: str
    smtp_host: str = "smtp.gmail.com"
    sent_count: int = field(default=0, repr=False)

    def send(self, recipient: str, message: str) -> bool:
        print(f"Email from {self.sender} to {recipient}: {message}")
        self.sent_count += 1
        return True


@dataclass
class SMSNotification(Notification):
    from_number: str

    def send(self, recipient: str, message: str) -> bool:
        if len(message) > 160:
            print(f"SMS too long ({len(message)} chars)")
            return False
        print(f"SMS from {self.from_number} to {recipient}: {message}")
        return True


class NotificationService:
    """Composes multiple notification channels."""

    def __init__(self):
        self._channels: list[Notification] = []

    def add_channel(self, channel: Notification):
        self._channels.append(channel)
        return self    # enables method chaining

    def broadcast(self, recipient: str, message: str):
        results = {}
        for channel in self._channels:
            name = channel.__class__.__name__
            results[name] = channel.send(recipient, message)
        return results


service = NotificationService()
service.add_channel(EmailNotification("noreply@app.com"))
service.add_channel(SMSNotification("+1234567890"))

results = service.broadcast("alice@example.com", "Your order shipped!")
print(results)
# {'EmailNotification': True, 'SMSNotification': True}
```

---

## The mental model to carry forward

OOP in Python is a set of tools, not a religion. Use each when it genuinely helps:

```
Need to model an is-a relationship?         → inheritance
Need to enforce an interface?               → ABC + @abstractmethod
Need to share small behaviors?              → mixins
Primarily holding data?                     → @dataclass
Need immutable value objects?               → @dataclass(frozen=True)
Need fine-grained attribute control?        → @property
Many instances, fixed attributes?           → __slots__
Objects that contain other objects?         → composition (usually preferred)
```

The sign of good OOP isn't how much you use it — it's whether the objects map naturally to the problem. If you're fighting the class hierarchy to make something work, step back and consider whether composition or a simpler approach solves it more cleanly.

---

Day 14 is the Week 2 project — the task manager gets rebuilt with everything from this week: file persistence, error handling, modules, and the class-based design from Days 12 and 13. Ready when you are.

[[Real Python]]