
This is a **buffered, periodically flushed, size-rotating log writer** for a Qt console app.

## What it does

`RotatingLogWriter` collects incoming text lines in memory, writes them to disk on a timer, and when the current log file gets too large, it closes it and starts a new file.

In `main.cpp`, a timer simulates incoming sensor/serial data so you can see buffering and rotation happen automatically.

---

## Core idea

Instead of writing every log line directly to disk:

1. `writeLine()` adds the line to `m_buffer`
2. A `QTimer` calls `flush()` every N milliseconds
3. `flush()` writes all buffered lines to the current file
4. If the file is now too large, `rotate()` opens a new file

This reduces frequent disk writes and gives simple log file rotation.

---

## Class walkthrough

### Constructor

```cpp
explicit RotatingLogWriter(const QString &directory,
                            qint64 maxBytesPerFile = 4096,
                            int flushIntervalMs = 2000,
                            QObject *parent = nullptr)
```

It configures:

- `directory`: where log files go
- `maxBytesPerFile`: threshold for rotation
- `flushIntervalMs`: how often buffered lines are written

Inside the constructor:

- `QDir().mkpath(m_directory);` ensures the log folder exists
- `m_flushTimer` is set up
- timer timeout is connected to `flush()`
- timer starts immediately
- `openNewFile()` creates the first log file

So after construction, the object is ready to accept lines.

---

## Buffering behavior

### `writeLine()`

```cpp
void writeLine(const QString &line)
{
    m_buffer.append(QDateTime::currentDateTime().toString(Qt::ISODate) + " " + line);
}
```

This does **not** write to disk immediately.

It:

- prepends a timestamp
- appends the result to `m_buffer`

Example stored line:

```text
2026-07-28T12:34:56 TEMP:22.5
```

Why buffer?

- fewer disk writes
- better performance
- easier batching

Tradeoff:

- if the app crashes before the next flush, buffered lines may be lost

---

## Periodic flushing

### `flush()`

```cpp
void flush()
```

This is called by the timer and also from the destructor.

Flow:

1. If buffer is empty, return immediately
2. Create `QTextStream` on `m_currentFile`
3. Write each buffered line plus newline
4. `out.flush()`
5. clear buffer
6. check file size
7. rotate if size limit reached

### Important detail

```cpp
if (m_buffer.isEmpty())
    return;
```

That avoids unnecessary work when nothing arrived since the last flush.

### Another important detail

```cpp
if (m_currentFile.size() >= m_maxBytesPerFile) {
    rotate();
}
```

Rotation is checked **after** writing. That means a file can slightly exceed the configured limit. This is normal and keeps the logic simple.

---

## Rotation

### `rotate()`

```cpp
void rotate()
{
    m_currentFile.close();
    openNewFile();
    emit fileRotated(m_currentFile.fileName());
}
```

This:

- closes the current file
- opens a new file with a timestamp-based name
- emits `fileRotated` so observers can react

The signal is useful for:

- UI updates
- debug output
- monitoring
- uploading/archive workflows

---

## Creating new log files

### `openNewFile()`

```cpp
const QString name = QString("log_%1.txt")
    .arg(QDateTime::currentDateTime().toString("yyyyMMdd_hhmmss_zzz"));
```

This generates names like:

```text
log_20260728_143012_123.txt
```

Then:

```cpp
m_currentFile.setFileName(m_directory + "/" + name);
m_currentFile.open(QIODevice::WriteOnly | QIODevice::Text)
```

Since each new file gets a unique timestamped name, old logs are preserved rather than overwritten.

If open fails, it prints a warning with `qWarning()`.

---

## Shutdown behavior

### Destructor

```cpp
~RotatingLogWriter() override
{
    flush();
    if (m_currentFile.isOpen())
        m_currentFile.close();
}
```

This is important:

- pending buffered lines are written before shutdown
- file handle is closed cleanly

Without this, the last buffered lines might never reach disk.

---

## `main.cpp` walkthrough

### App setup

```cpp
QCoreApplication app(argc, argv);
```

This is a non-GUI Qt event loop application.

### Writer creation

```cpp
auto *writer = new RotatingLogWriter("./logs", 300, 1000);
```

This means:

- logs go in `./logs`
- rotate after about 300 bytes
- flush every 1000 ms

The byte limit is intentionally tiny so rotation happens quickly in the demo.

### Rotation notification

```cpp
QObject::connect(writer, &RotatingLogWriter::fileRotated, [](const QString &path) {
    qDebug() << "[ROTATED] now writing to:" << path;
});
```

Whenever rotation happens, the app prints the new file path.

### Fake input generator

```cpp
QTimer lineGenerator;
int counter = 0;
QObject::connect(&lineGenerator, &QTimer::timeout, [&]() {
    ++counter;
    writer->writeLine(QString("TEMP:%1").arg(20.0 + (counter % 10) * 0.5));
});
lineGenerator.start(150);
```

Every 150 ms it generates a line like:

```text
TEMP:20
TEMP:20.5
TEMP:21
...
```

Because generation is faster than flushing:

- several lines accumulate in memory
- every second, they are written together

That demonstrates batching.

### Timed shutdown

```cpp
QTimer::singleShot(6000, &app, &QCoreApplication::quit);
```

The app runs for 6 seconds, then exits.

### Cleanup

```cpp
delete writer;
```

Because `writer` has no parent, it must be deleted manually. Deleting it triggers the destructor, which flushes any remaining buffered lines.

---

## Why this design is useful

### Strengths

- **Simple**
  - easy to understand and extend
- **Efficient enough for many apps**
  - avoids disk writes for every message
- **Automatic rotation**
  - prevents one endlessly growing file
- **Qt-friendly**
  - uses signals, slots, timers, and QObject patterns
- **Safe shutdown**
  - buffered data is flushed in destructor

---

## Behavior timeline in this demo

Roughly:

- every 150 ms: one line added to buffer
- every 1000 ms: buffered lines written to file
- once file reaches ~300 bytes: rotate to a new file
- after 6 seconds: app quits and final buffered lines are flushed

So you should end up with multiple small log files in `./logs`.

---

## Subtle implementation notes

### 1. Rotation happens after flush
A file may exceed `maxBytesPerFile` by up to the size of the last batch.

### 2. `QTextStream` is recreated each flush
That’s fine here because it writes to the same open `QFile`.

### 3. No thread protection
This class assumes calls happen in one thread/event loop. If `writeLine()` were called from multiple threads, you'd need synchronization or queued connections.

### 4. If file open fails
The code warns, but `flush()` still attempts to write to `m_currentFile`. In production, you'd probably want stronger error handling.

### 5. Timestamp-based filenames
This is simple and readable, but if rotation somehow occurred multiple times within the same millisecond, naming collisions could happen. Extremely unlikely in this demo.

---

## In plain English

Think of it like this:

- incoming messages go into a bucket (`m_buffer`)
- once per second, the bucket is poured into a file
- when the file gets too full, a new file is started
- when the app exits, anything left in the bucket is poured out first

---

## Possible improvements

If you wanted to evolve this into production code, likely additions would be:

- thread safety
- explicit error recovery if file open/write fails
- max number of retained log files
- date-based rotation as an option
- immediate flush for high-priority messages
- configurable timestamp format
- append-to-existing-file behavior on restart
- mutex or queued logging API for multi-threaded producers

---

## Summary

This solution demonstrates a clean Qt logging pattern:

- **buffer log lines in memory**
- **flush them periodically with `QTimer`**
- **rotate files by size**
- **flush on shutdown to avoid data loss**

`main.cpp` simply drives the class with synthetic data so you can observe the behavior. If you want, I can also explain it **line by line** or show a **sequence diagram of the runtime flow**.

> [!NOTE]
> Explain the code, step by step

Here’s the code explained **top to bottom, step by step**.

---

# 1. `rotatinglogwriter.h`

## Header guard and includes

```cpp
#pragma once
#include <QObject>
#include <QStringList>
#include <QTimer>
#include <QFile>
#include <QDir>
#include <QDateTime>
```

### What this does

- `#pragma once` prevents the header from being included multiple times.
- The Qt headers provide the tools this class needs:
  - `QObject` for signals/slots and object lifetime support
  - `QStringList` for buffering lines in memory
  - `QTimer` for periodic flushing
  - `QFile` for writing log files
  - `QDir` for creating the log directory
  - `QDateTime` for timestamps and file names

---

## Class declaration

```cpp
class RotatingLogWriter : public QObject
{
    Q_OBJECT
```

### What this means

- `RotatingLogWriter` is a Qt object.
- Inheriting from `QObject` lets it use:
  - signals
  - slots
  - timer connections
  - parent/child ownership patterns
- `Q_OBJECT` enables Qt’s meta-object features required for signals and slots.

---

## Public constructor

```cpp
public:
    explicit RotatingLogWriter(const QString &directory,
                                qint64 maxBytesPerFile = 4096,   // small for demo purposes
                                int flushIntervalMs = 2000,
                                QObject *parent = nullptr)
```

### Parameters

This constructor takes 4 inputs:

1. `directory`
   - folder where log files will be stored

2. `maxBytesPerFile`
   - maximum file size before rotation happens
   - default is `4096`

3. `flushIntervalMs`
   - how often buffered lines should be written to disk
   - default is `2000` ms

4. `parent`
   - optional Qt parent object for ownership

---

## Member initializer list

```cpp
        : QObject(parent)
        , m_directory(directory)
        , m_maxBytesPerFile(maxBytesPerFile)
```

### What happens here

Before the constructor body runs:

- the base `QObject` is initialized with `parent`
- `m_directory` is set from the constructor argument
- `m_maxBytesPerFile` is set from the constructor argument

This is the proper C++ way to initialize members.

---

## Ensure log directory exists

```cpp
    {
        QDir().mkpath(m_directory);   // ensure the log directory exists
```

### Step

- Creates the directory if it doesn’t already exist.
- If `./logs` is passed, Qt makes that folder.

Without this, opening a file inside a missing directory would fail.

---

## Configure the flush timer

```cpp
        m_flushTimer.setInterval(flushIntervalMs);
        m_flushTimer.setTimerType(Qt::CoarseTimer);   // per Day 4: flushing isn't timing-critical
```

### Step

- `setInterval(flushIntervalMs)` tells the timer how often to fire.
- `Qt::CoarseTimer` says very precise timing is not required.

That’s reasonable for logging, since being off by a few milliseconds doesn’t matter.

---

## Connect timer to flush

```cpp
        connect(&m_flushTimer, &QTimer::timeout, this, &RotatingLogWriter::flush);
```

### Step

This means:

- every time `m_flushTimer` times out
- call `this->flush()`

So `flush()` runs automatically at the chosen interval.

---

## Start the timer

```cpp
        m_flushTimer.start();
```

### Step

The periodic flushing begins immediately.

---

## Open the first log file

```cpp
        openNewFile();   // start with a fresh file immediately
    }
```

### Step

As soon as the writer is created, it opens the first output file.

That way the object is ready before any log lines arrive.

---

## Destructor

```cpp
    ~RotatingLogWriter() override
    {
        flush();   // don't lose buffered lines on shutdown
        if (m_currentFile.isOpen())
            m_currentFile.close();
    }
```

### Step by step

When the object is destroyed:

1. `flush()` writes any remaining buffered lines to disk
2. if a file is still open, close it

This prevents losing the last unwritten messages.

---

## Public slot: `writeLine`

```cpp
public slots:
    // Intended connection target for something like SerialReader::lineReceived
    void writeLine(const QString &line)
    {
        m_buffer.append(QDateTime::currentDateTime().toString(Qt::ISODate) + " " + line);
    }
```

### What this is for

This function is meant to receive incoming log lines.

### Step by step

When `writeLine("TEMP:22.5")` is called:

1. `QDateTime::currentDateTime()` gets the current local date/time
2. `.toString(Qt::ISODate)` converts it into a string like:
   - `2026-07-28T14:30:10`
3. `" " + line` adds the original message after a space
4. the final string is appended to `m_buffer`

So the buffer stores lines like:

```text
2026-07-28T14:30:10 TEMP:22.5
```

### Important

This function does **not** write to disk immediately.  
It only stores the line in memory.

---

## Signal declaration

```cpp
signals:
    void fileRotated(const QString &newPath);
```

### What this means

This signal is emitted whenever a new log file becomes active after rotation.

Other parts of the program can connect to it and react.

Example use:
- print debug info
- update UI
- notify another subsystem

---

## Private slot: `flush`

```cpp
private slots:
    void flush()
    {
        if (m_buffer.isEmpty())
            return;   // nothing buffered -- avoid a pointless disk write (Day 4 discipline: cheap no-ops)
```

### Step 1: check whether there is anything to write

- If the buffer is empty, return immediately.
- No file work is done.

This avoids unnecessary disk operations.

---

### Create a text stream

```cpp
        QTextStream out(&m_currentFile);
```

### Step 2

- A `QTextStream` is created to write text into the current file.

`QTextStream` makes line-based text output easy.

---

### Write all buffered lines

```cpp
        for (const QString &line : std::as_const(m_buffer)) {
            out << line << '\n';
        }
```

### Step 3

For every line currently in the buffer:

- write the line to the file
- add a newline character

`std::as_const(m_buffer)` prevents accidental modification during iteration.

---

### Flush stream output

```cpp
        out.flush();
```

### Step 4

This pushes the `QTextStream`'s buffered text into the file device.

It improves confidence that the text has actually been sent down to `QFile`.

---

### Clear the in-memory buffer

```cpp
        m_buffer.clear();   // per Day 6: clearing a QList that's not shared elsewhere is cheap, no detach drama here
```

### Step 5

Once the lines are written, the in-memory buffer is emptied.

This prepares it for the next batch of incoming lines.

---

### Check file size and rotate if needed

```cpp
        if (m_currentFile.size() >= m_maxBytesPerFile) {
            rotate();
        }
    }
```

### Step 6

After writing the batch:

- ask the current file for its size
- if size is at or above the configured limit, rotate

### Subtle point

Rotation happens **after** writing the batch, not before.  
So one file may end up a little larger than `m_maxBytesPerFile`.

That is normal in simple log rotation systems.

---

## Private slot: `rotate`

```cpp
    void rotate()
    {
        m_currentFile.close();
        openNewFile();
        emit fileRotated(m_currentFile.fileName());
    }
```

### Step by step

When rotation is needed:

1. close the current file
2. open a new one with a fresh timestamped name
3. emit `fileRotated(...)` with the new file path

So after this, future flushes write into the new file.

---

## Private helper: `openNewFile`

```cpp
private:
    void openNewFile()
    {
        const QString name = QString("log_%1.txt")
            .arg(QDateTime::currentDateTime().toString("yyyyMMdd_hhmmss_zzz"));
```

### Step 1: build a unique filename

This creates a file name like:

```text
log_20260728_143010_123.txt
```

Format pieces:

- `yyyy` = year
- `MM` = month
- `dd` = day
- `hh` = hour
- `mm` = minute
- `ss` = second
- `zzz` = milliseconds

So each rotated file gets a timestamp in its name.

---

### Set the full file path

```cpp
        m_currentFile.setFileName(m_directory + "/" + name);
```

### Step 2

This combines the directory and generated filename.

Example:

```text
./logs/log_20260728_143010_123.txt
```

---

### Open file for writing

```cpp
        if (!m_currentFile.open(QIODevice::WriteOnly | QIODevice::Text)) {
            qWarning() << "Failed to open log file:" << m_currentFile.fileName();
        }
    }
```

### Step 3

Try to open the file.

Flags used:

- `QIODevice::WriteOnly` = write mode
- `QIODevice::Text` = text mode

If opening fails:

- print a warning to the debug output

---

## Private data members

```cpp
    QString m_directory;
    qint64 m_maxBytesPerFile;
    QStringList m_buffer;
    QFile m_currentFile;
    QTimer m_flushTimer;
};
```

### What each member stores

- `m_directory`
  - log folder path

- `m_maxBytesPerFile`
  - size limit before rotation

- `m_buffer`
  - in-memory list of pending log lines

- `m_currentFile`
  - currently open output file

- `m_flushTimer`
  - periodic timer that triggers `flush()`

---

# 2. `main.cpp`

Now the demo program.

---

## Includes

```cpp
#include <QCoreApplication>
#include <QTimer>
#include <QDebug>
#include "rotatinglogwriter.h"
```

### Why these are needed

- `QCoreApplication` gives a Qt event loop for a console app
- `QTimer` is used to simulate incoming data
- `QDebug` is used for console logging
- `rotatinglogwriter.h` brings in the custom class

---

## Main function start

```cpp
int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);
```

### Step

Creates the application object and event loop.

Since this is a Qt timer/signal-based program, the event loop is essential.

---

## Create the log writer

```cpp
    // Parented to nothing explicit -- app's implicit lifetime covers the whole run;
    // in a real service this would be parented to your top-level service object (Day 5).
    auto *writer = new RotatingLogWriter("./logs", /*maxBytesPerFile=*/300, /*flushIntervalMs=*/1000);
```

### Step

Allocate a `RotatingLogWriter` on the heap.

Arguments mean:

- log directory: `./logs`
- rotate when file reaches about `300` bytes
- flush every `1000` ms

### Why 300 bytes?

It is intentionally tiny so rotation happens during the short demo.

In real software, this would usually be much larger.

---

## React to file rotation

```cpp
    QObject::connect(writer, &RotatingLogWriter::fileRotated, [](const QString &path) {
        qDebug() << "[ROTATED] now writing to:" << path;
    });
```

### Step

Connect the `fileRotated` signal to a lambda.

Whenever rotation happens:

- the lambda runs
- it prints the path of the new active file

So the console shows rotation activity.

---

## Simulate incoming lines

```cpp
    // Simulate a SerialReader emitting lines faster than the flush interval,
    // to prove buffering + periodic flush + rotation all work together.
    QTimer lineGenerator;
    int counter = 0;
```

### Step

- create a timer called `lineGenerator`
- create a counter to vary the generated values

This timer acts like a fake data source.

---

## Connect generator timeout to log-writing lambda

```cpp
    QObject::connect(&lineGenerator, &QTimer::timeout, [&]() {
        ++counter;
        writer->writeLine(QString("TEMP:%1").arg(20.0 + (counter % 10) * 0.5));
    });
```

### Step by step

Every time `lineGenerator` fires:

1. increment `counter`
2. calculate a temperature-like value:
   - `counter % 10` cycles from 0 to 9
   - multiplied by `0.5`
   - added to `20.0`
3. create a string like:
   - `TEMP:20`
   - `TEMP:20.5`
   - `TEMP:21`
   - etc.
4. call `writer->writeLine(...)`

That adds the line to the writer’s memory buffer.

---

## Start generator timer

```cpp
    lineGenerator.start(150);   // faster than the 1000ms flush -- several lines buffer per flush
```

### Step

The generator fires every `150` ms.

Since flushing happens every `1000` ms, multiple lines accumulate before each flush.

Roughly:
- about 6 or 7 lines arrive per second
- then one batch write happens

This demonstrates buffering clearly.

---

## Schedule application shutdown

```cpp
    QTimer::singleShot(6000, &app, &QCoreApplication::quit);   // run for 6 seconds, then exit
```

### Step

This sets a one-time timer:

- after `6000` ms
- call `app.quit()`

So the program automatically stops after 6 seconds.

---

## Enter event loop

```cpp
    int result = app.exec();
```

### Step

This starts the Qt event loop.

While the loop runs:

- `lineGenerator` keeps generating lines
- `m_flushTimer` inside `RotatingLogWriter` keeps flushing
- rotation may happen multiple times

The function stays here until `quit()` is called.

---

## Manual cleanup

```cpp
    delete writer;   // manual delete here since it has no parent (per Day 5 rules) -- flushes remaining lines in destructor
    return result;
}
```

### Step

After the event loop ends:

1. `delete writer`
   - calls `RotatingLogWriter` destructor
   - destructor calls `flush()`
   - final buffered lines are written
   - file closes

2. return the application exit code

Because `writer` was created with `new` and no parent, manual deletion is required.

---

# 3. Runtime flow from start to finish

Here is the complete sequence.

## Startup

1. `QCoreApplication` starts
2. `RotatingLogWriter` is created
3. log directory is created if needed
4. flush timer is configured and started
5. first log file is opened

## During execution

6. every 150 ms, `lineGenerator` produces a temperature string
7. `writeLine()` adds timestamped text to `m_buffer`
8. every 1000 ms, `flush()` writes the whole buffer to disk
9. after flush, if file size exceeds limit, `rotate()` creates a new file
10. `fileRotated` signal prints a debug message

## Shutdown

11. after 6000 ms, `app.quit()` stops the event loop
12. `delete writer` runs
13. destructor calls `flush()` one last time
14. current file closes
15. program exits

---

# 4. Example of what happens in practice

Suppose these lines arrive before the first flush:

```text
TEMP:20.5
TEMP:21
TEMP:21.5
TEMP:22
TEMP:22.5
TEMP:23
```

They are stored in memory as:

```text
2026-07-28T14:30:01 TEMP:20.5
2026-07-28T14:30:01 TEMP:21
2026-07-28T14:30:01 TEMP:21.5
...
```

When the flush timer fires, all of them are written together to the current log file.

If that makes the file exceed 300 bytes, rotation happens and the next flush writes to a fresh file.

---

# 5. Why this approach is useful

## Better than writing every line immediately

If every line were written directly to disk:

- more I/O calls
- more overhead
- poorer performance under frequent events

This design batches writes, which is often more efficient.

## Better than one giant log file

If logs never rotated:

- one file grows forever
- harder to inspect
- harder to manage

Rotation keeps files smaller and more manageable.

---

# 6. Important caveats

## Buffered data can be lost on crash

Because lines stay in memory until the next flush, a crash may lose recent lines.

## Not thread-safe as written

If multiple threads call `writeLine()` directly, you’d need synchronization.

## File size check is post-write

The file can exceed the exact size threshold by one batch.

## Open failure only logs a warning

The code does not fully recover from file open errors.

---

# 7. One-sentence summary

This program builds a Qt log writer that **buffers timestamped lines in memory, flushes them to disk on a timer, and starts a new file whenever the current one gets too large**.

If you want, I can next explain it as:
1. **a sequence diagram**, or  
2. **a line-by-line annotated version of the actual code**.