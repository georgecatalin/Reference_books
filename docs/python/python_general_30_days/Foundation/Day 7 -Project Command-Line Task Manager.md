[[Foundation]]

Today you build something real. No isolated snippets — a complete, working program that uses everything from Week 1.

## What you're building

A command-line task manager that:

- Adds tasks with a title and priority
- Lists all tasks
- Marks tasks as complete
- Deletes tasks
- Filters tasks by status or priority
- Persists nothing yet (that's Week 2) — tasks live in memory for now

---

## How to approach a project before writing code

Before touching the keyboard, answer these three questions:

**What data do I need to store?** A task needs: an ID, a title, a priority, a status (done or not), a creation order.

**What operations do I need?** Add, list, complete, delete, filter.

**How do I structure the program?** One function per operation. A main loop that reads commands. A data structure to hold tasks.

This thinking takes 5 minutes and saves an hour of rewriting.

---

## The data structure

```python
# A task is a dictionary — fixed fields, named access
task = {
    "id": 1,
    "title": "Buy groceries",
    "priority": "high",
    "done": False
}

# All tasks live in a list — ordered, we'll add/remove items
tasks = []
```

---

## The complete program

Build this file as `task_manager.py`. Type it out — don't paste.

```python
# task_manager.py

tasks = []
next_id = 1


def add_task(title, priority="medium"):
    """Add a new task to the task list."""
    global next_id

    valid_priorities = {"low", "medium", "high"}
    if priority not in valid_priorities:
        print(f"Invalid priority '{priority}'. Choose: low, medium, high")
        return None

    task = {
        "id": next_id,
        "title": title,
        "priority": priority,
        "done": False
    }
    tasks.append(task)
    next_id += 1
    print(f"Added: [{task['id']}] {title} ({priority})")
    return task


def list_tasks(filter_status=None, filter_priority=None):
    """List tasks, optionally filtered by status or priority."""
    if not tasks:
        print("No tasks found.")
        return

    filtered = tasks

    if filter_status == "done":
        filtered = [t for t in filtered if t["done"]]
    elif filter_status == "pending":
        filtered = [t for t in filtered if not t["done"]]

    if filter_priority:
        filtered = [t for t in filtered if t["priority"] == filter_priority]

    if not filtered:
        print("No tasks match that filter.")
        return

    priority_order = {"high": 0, "medium": 1, "low": 2}
    filtered = sorted(filtered, key=lambda t: priority_order[t["priority"]])

    print(f"\n{'ID':<5} {'STATUS':<10} {'PRIORITY':<10} {'TITLE'}")
    print("-" * 45)
    for task in filtered:
        status = "done" if task["done"] else "pending"
        print(f"{task['id']:<5} {status:<10} {task['priority']:<10} {task['title']}")
    print()


def complete_task(task_id):
    """Mark a task as complete by ID."""
    for task in tasks:
        if task["id"] == task_id:
            if task["done"]:
                print(f"Task {task_id} is already done.")
            else:
                task["done"] = True
                print(f"Completed: [{task_id}] {task['title']}")
            return

    print(f"No task with ID {task_id}.")


def delete_task(task_id):
    """Delete a task by ID."""
    for i, task in enumerate(tasks):
        if task["id"] == task_id:
            removed = tasks.pop(i)
            print(f"Deleted: [{task_id}] {removed['title']}")
            return

    print(f"No task with ID {task_id}.")


def show_summary():
    """Print a summary of task counts."""
    total = len(tasks)
    done = sum(1 for t in tasks if t["done"])
    pending = total - done

    high = sum(1 for t in tasks if t["priority"] == "high" and not t["done"])

    print(f"\nTotal: {total} | Done: {done} | Pending: {pending} | High priority pending: {high}\n")


def parse_command(user_input):
    """Parse and execute a command string."""
    parts = user_input.strip().split()

    if not parts:
        return

    command = parts[0].lower()

    if command == "add":
        if len(parts) < 2:
            print("Usage: add <title> [priority]")
            return
        # Last word is priority if it matches, otherwise it's part of title
        if parts[-1] in ("low", "medium", "high") and len(parts) > 2:
            title = " ".join(parts[1:-1])
            priority = parts[-1]
        else:
            title = " ".join(parts[1:])
            priority = "medium"
        add_task(title, priority)

    elif command == "list":
        filter_status = None
        filter_priority = None
        if len(parts) > 1:
            arg = parts[1].lower()
            if arg in ("done", "pending"):
                filter_status = arg
            elif arg in ("low", "medium", "high"):
                filter_priority = arg
        list_tasks(filter_status, filter_priority)

    elif command == "done":
        if len(parts) < 2 or not parts[1].isdigit():
            print("Usage: done <id>")
            return
        complete_task(int(parts[1]))

    elif command == "delete":
        if len(parts) < 2 or not parts[1].isdigit():
            print("Usage: delete <id>")
            return
        delete_task(int(parts[1]))

    elif command == "summary":
        show_summary()

    elif command == "help":
        print("""
Commands:
  add <title> [low|medium|high]   Add a task (default priority: medium)
  list                            List all tasks
  list done                       List completed tasks
  list pending                    List pending tasks
  list high                       List high priority tasks
  done <id>                       Mark task as complete
  delete <id>                     Delete a task
  summary                         Show task counts
  quit                            Exit
        """)

    elif command == "quit":
        return "quit"

    else:
        print(f"Unknown command '{command}'. Type 'help' for commands.")


def main():
    """Main loop — runs the program."""
    print("Task Manager — type 'help' for commands\n")

    # Seed some tasks so you can test immediately
    add_task("Set up development environment", "high")
    add_task("Read project documentation", "medium")
    add_task("Write unit tests", "high")
    add_task("Update README", "low")
    add_task("Fix login bug", "high")

    while True:
        try:
            user_input = input("\n> ").strip()
            result = parse_command(user_input)
            if result == "quit":
                print("Goodbye.")
                break
        except KeyboardInterrupt:
            print("\nGoodbye.")
            break


if __name__ == "__main__":
    main()
```

Run it:

```bash
python task_manager.py
```

---

## What to test after running it

Run these commands in order and observe the output:

```
help
list
list high
list pending
done 1
done 1
list done
delete 4
list
summary
add Buy milk low
add Fix critical bug high
list
summary
done 99
delete 99
quit
```

Each command should behave exactly as described in the help text. If anything is broken or confusing, that's your first debugging session.

---

## What Week 1 concepts appear in this project

|Concept|Where it appears|
|---|---|
|Variables|`tasks`, `next_id`, task fields|
|Strings|f-strings, `.strip()`, `.split()`, `.lower()`, `.join()`|
|Integers|task IDs, counts, `isdigit()`|
|Booleans|`task["done"]`, truthiness checks|
|Lists|`tasks` list, list operations, list comprehensions|
|Dicts|Each task is a dict|
|Sets|`valid_priorities` — membership check|
|For loops|Iterating tasks, counting with `sum()`|
|While loop|Main program loop|
|If/elif/else|Command parsing, filter logic, validation|
|break|Exiting the main loop|
|Functions|Every operation is a function|
|Default args|`priority="medium"`, `filter_status=None`|
|*args/**kwargs|Not used here — intentional, you don't always need them|
|Docstrings|Every function documented|
|Early returns|`complete_task`, `delete_task`, `parse_command`|
|global|`next_id` — and notice it's slightly awkward, which is the point|

---

## What to notice about the design

**`global next_id` is a code smell.** It works here, but it's already uncomfortable — you have to remember to declare it global in `add_task`, and it's invisible state. Week 2 replaces this with a class where state lives naturally inside the object.

**`parse_command` is too long.** It handles every command in one function. A cleaner design would be a dispatch table — a dict mapping command names to functions. You have the knowledge to refactor this now if you want the challenge.

**No persistence.** Every time you restart the program, tasks are gone. Week 2 fixes this — you'll save tasks to a file and load them on startup.

**Error handling is minimal.** `isdigit()` catches non-numeric IDs but there's more that could go wrong. Week 2 introduces proper `try/except`.

---

## The refactoring challenge (optional, do it before Day 8)

Replace the `if/elif` chain in `parse_command` with a dispatch table:

```python
# Hint — structure it like this:
commands = {
    "add": handle_add,
    "list": handle_list,
    "done": handle_done,
    "delete": handle_delete,
    "summary": show_summary,
    "help": show_help,
}

command = parts[0].lower()
handler = commands.get(command)
if handler:
    handler(parts)
else:
    print(f"Unknown command '{command}'")
```

Each handler takes `parts` as its argument and does its own argument parsing internally. This is a real pattern used in production CLI tools.

---

Week 1 is done. You've covered every foundational concept and built something that actually runs.

Week 2 starts Monday — file I/O, error handling, modules, comprehensions, and OOP. The task manager gets persistence, proper error handling, and eventually a class-based redesign.

Day 8 whenever you're ready.