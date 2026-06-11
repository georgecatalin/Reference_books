

Static libraries from Day 10 copy code into your binary at link time. Dynamic libraries (`.so` files on Linux) are loaded by the runtime linker — either automatically at startup or explicitly at runtime with `dlopen`. The explicit loading pattern is the foundation of plugin architectures: a core program that gains new capabilities by loading modules it never knew about at compile time.

---

## Static vs dynamic linking — what actually happens

```
Static linking (Day 10):
  your_code.o + libsensor.a → single binary, all code inside
  Deploy: copy one file. No external dependencies.
  Update library: must recompile and redeploy the binary.

Dynamic linking (startup):
  your_code.o + libsensor.so → binary with unresolved references
  Runtime linker resolves references when program starts.
  Deploy: binary + .so files must both be present.
  Update library: replace .so, binary unchanged.

Dynamic loading (dlopen):
  your_code.o → binary, no knowledge of plugins at compile time
  dlopen("plugin.so") at runtime — load any .so explicitly.
  Deploy: core binary + whatever plugins are installed.
  Update plugin: replace one .so file, no recompile needed.
```

---

## Building a shared library

A `.so` file is compiled with `-fPIC` (Position Independent Code) — the code uses relative addressing so it can be loaded at any address:

```bash
# Compile with -fPIC
gcc -Wall -Wextra -fPIC -c sensor.c -o sensor.o

# Link into a shared library
gcc -shared -o libsensor.so sensor.o

# Versioned shared library — production practice
gcc -shared -Wl,-soname,libsensor.so.1 -o libsensor.so.1.0.0 sensor.o
ln -s libsensor.so.1.0.0 libsensor.so.1
ln -s libsensor.so.1     libsensor.so

# Link your program against it
gcc -o myapp main.c -L. -lsensor -Wl,-rpath,'$ORIGIN'
# -Wl,-rpath,'$ORIGIN': look for .so in same directory as binary at runtime
```

Symbol visibility — controlling what's exported from your `.so`:

```c
/* Mark symbols as visible (exported) or hidden */
__attribute__((visibility("default"))) int public_function(void);
__attribute__((visibility("hidden")))  int private_helper(void);

/* Or set hidden as the default and explicitly export what you want */
/* Compile with: -fvisibility=hidden */
/* Then mark exports: */
#define API __attribute__((visibility("default")))

API int sensor_init(void);
API int sensor_read(float *out);
API void sensor_close(void);
```

Controlling visibility reduces the exported symbol table, prevents name collisions between plugins, and allows the linker to optimise more aggressively.

---

## `dlopen` / `dlsym` / `dlclose`

The explicit loading API:

```c
#include <dlfcn.h>
/* Link with: -ldl */

/* Load a shared library */
void *handle = dlopen("./plugin.so", RTLD_NOW | RTLD_LOCAL);
if (!handle) {
    fprintf(stderr, "dlopen: %s\n", dlerror());
    return -1;
}

/* Look up a symbol by name — returns void * */
void *sym = dlsym(handle, "sensor_read");
if (!sym) {
    fprintf(stderr, "dlsym: %s\n", dlerror());
    dlclose(handle);
    return -1;
}

/* Cast to the correct function pointer type and call */
typedef int (*sensor_read_fn)(float *out);
sensor_read_fn read_fn = (sensor_read_fn)sym;
float val;
int rc = read_fn(&val);

/* Unload when done */
dlclose(handle);
```

`dlopen` flags:

|Flag|Meaning|
|---|---|
|`RTLD_NOW`|Resolve all symbols immediately — fail fast if any are missing|
|`RTLD_LAZY`|Resolve symbols on first use — deferred error detection|
|`RTLD_LOCAL`|Symbols not available to other loaded libraries|
|`RTLD_GLOBAL`|Symbols available to subsequently loaded libraries|
|`RTLD_NODELETE`|Don't unload on `dlclose` — useful for libraries with static state|

Always use `RTLD_NOW | RTLD_LOCAL` unless you have a specific reason not to. `RTLD_NOW` catches missing symbols at load time rather than at the worst possible moment during execution.

---

## A plugin architecture for sensor drivers

The design: a core application that loads sensor driver plugins at startup. Each plugin implements a standard interface. The core doesn't know or care which sensors are installed — it discovers them by scanning a directory.

```c
/* plugin_api.h — the contract every plugin must implement */
#pragma once
#include <stdint.h>
#include <stdbool.h>

#define PLUGIN_API_VERSION  2

/*
 * Every plugin exports one symbol: "sensor_plugin"
 * of type SensorPlugin.
 */
typedef struct {
    /* Metadata */
    uint32_t    api_version;   /* must equal PLUGIN_API_VERSION */
    const char *name;          /* "temperature", "humidity", etc. */
    const char *description;
    const char *version;       /* plugin's own version string */

    /* Lifecycle */
    int  (*init)(const char *config_str);  /* 0=ok, negative=error */
    void (*shutdown)(void);

    /* Operation */
    int  (*read)(float *value, uint8_t *device_id); /* 0=ok, -1=error */
    bool (*is_available)(void);                     /* hardware present? */

    /* Optional — set to NULL if not supported */
    int  (*configure)(const char *key, const char *value);
    int  (*self_test)(char *report_buf, size_t buf_sz);
} SensorPlugin;
```

```c
/* temperature_plugin.c — a concrete driver */
#include "plugin_api.h"
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>

/* Private state — not visible outside this .so */
static char g_device_path[64] = "/dev/ttyUSB0";
static int  g_initialized     = 0;

static int temp_init(const char *config_str) {
    if (config_str && strlen(config_str) < sizeof(g_device_path)) {
        strncpy(g_device_path, config_str, sizeof(g_device_path) - 1);
    }
    /* In real code: open g_device_path, configure termios, etc. */
    g_initialized = 1;
    return 0;
}

static void temp_shutdown(void) {
    /* In real code: close fd, flush buffers */
    g_initialized = 0;
}

static int temp_read(float *value, uint8_t *device_id) {
    if (!g_initialized) return -1;
    /* In real code: read from serial, parse frame */
    /* Simulated: return a fake reading */
    *value     = 20.0f + (float)(rand() % 100) * 0.1f;
    *device_id = 1;
    return 0;
}

static bool temp_available(void) {
    /* In real code: check if device file exists */
    return g_initialized;
}

static int temp_self_test(char *buf, size_t sz) {
    float v; uint8_t id;
    int rc = temp_read(&v, &id);
    snprintf(buf, sz, "self_test: %s val=%.2f",
             rc == 0 ? "PASS" : "FAIL", v);
    return rc;
}

/*
 * The single exported symbol — the plugin descriptor.
 * 'default' visibility means dlsym can find it.
 */
__attribute__((visibility("default")))
const SensorPlugin sensor_plugin = {
    .api_version = PLUGIN_API_VERSION,
    .name        = "temperature",
    .description = "DS18B20 1-wire temperature sensor",
    .version     = "1.2.0",
    .init        = temp_init,
    .shutdown    = temp_shutdown,
    .read        = temp_read,
    .is_available= temp_available,
    .configure   = NULL,          /* not supported */
    .self_test   = temp_self_test,
};
```

```c
/* humidity_plugin.c — second plugin, independent .so */
#include "plugin_api.h"
#include <stdlib.h>
#include <stdio.h>

static int  g_init = 0;

static int  hum_init(const char *cfg) { (void)cfg; g_init=1; return 0; }
static void hum_shutdown(void)        { g_init = 0; }
static int  hum_read(float *v, uint8_t *id) {
    if (!g_init) return -1;
    *v  = 40.0f + (float)(rand() % 400) * 0.1f;
    *id = 2;
    return 0;
}
static bool hum_available(void) { return g_init; }

__attribute__((visibility("default")))
const SensorPlugin sensor_plugin = {
    .api_version = PLUGIN_API_VERSION,
    .name        = "humidity",
    .description = "SHT31 I2C humidity sensor",
    .version     = "0.9.1",
    .init        = hum_init,
    .shutdown    = hum_shutdown,
    .read        = hum_read,
    .is_available= hum_available,
    .configure   = NULL,
    .self_test   = NULL,
};
```

---

## The plugin loader

```c
/* plugin_loader.c */
#include "plugin_api.h"
#include "log.h"
#include <dlfcn.h>
#include <dirent.h>
#include <string.h>
#include <stdlib.h>
#include <stdio.h>

#define MAX_PLUGINS 16

typedef struct {
    void               *handle;       /* dlopen handle */
    const SensorPlugin *plugin;       /* pointer to plugin's descriptor */
    char                path[256];    /* path to .so file */
} LoadedPlugin;

static LoadedPlugin g_plugins[MAX_PLUGINS];
static int          g_plugin_count = 0;

/*
 * Load a single plugin .so file.
 * Returns 0 on success, -1 on failure.
 */
int plugin_load(const char *path) {
    if (g_plugin_count >= MAX_PLUGINS) {
        LOG_ERROR("max plugins (%d) reached", MAX_PLUGINS);
        return -1;
    }

    /* Clear any previous dlerror */
    dlerror();

    void *handle = dlopen(path, RTLD_NOW | RTLD_LOCAL);
    if (!handle) {
        LOG_ERROR("dlopen %s: %s", path, dlerror());
        return -1;
    }

    /* Look up the plugin descriptor */
    dlerror();
    const SensorPlugin *p = dlsym(handle, "sensor_plugin");
    const char *err = dlerror();
    if (err) {
        LOG_ERROR("dlsym sensor_plugin in %s: %s", path, err);
        dlclose(handle);
        return -1;
    }

    /* API version check — reject incompatible plugins */
    if (p->api_version != PLUGIN_API_VERSION) {
        LOG_ERROR("plugin %s: API version %u != expected %u",
                  path, p->api_version, PLUGIN_API_VERSION);
        dlclose(handle);
        return -1;
    }

    /* Initialise the plugin */
    if (p->init && p->init(NULL) < 0) {
        LOG_ERROR("plugin %s: init failed", p->name);
        dlclose(handle);
        return -1;
    }

    LoadedPlugin *lp = &g_plugins[g_plugin_count++];
    lp->handle = handle;
    lp->plugin = p;
    strncpy(lp->path, path, sizeof(lp->path) - 1);

    LOG_INFO("loaded plugin: %s v%s (%s)",
             p->name, p->version, p->description);
    return 0;
}

/*
 * Scan a directory and load every .so file found.
 */
int plugin_load_dir(const char *dir_path) {
    DIR *dir = opendir(dir_path);
    if (!dir) {
        LOG_ERRNO("opendir");
        return -1;
    }

    int loaded = 0;
    struct dirent *ent;
    while ((ent = readdir(dir)) != NULL) {
        /* Skip non-.so files */
        size_t len = strlen(ent->d_name);
        if (len < 3 || strcmp(ent->d_name + len - 3, ".so") != 0)
            continue;

        char path[512];
        snprintf(path, sizeof(path), "%s/%s", dir_path, ent->d_name);

        if (plugin_load(path) == 0) loaded++;
    }

    closedir(dir);
    LOG_INFO("loaded %d plugins from %s", loaded, dir_path);
    return loaded;
}

/*
 * Unload all plugins — call at program exit.
 */
void plugin_unload_all(void) {
    for (int i = g_plugin_count - 1; i >= 0; i--) {
        const SensorPlugin *p = g_plugins[i].plugin;
        LOG_INFO("unloading plugin: %s", p->name);
        if (p->shutdown) p->shutdown();
        dlclose(g_plugins[i].handle);
    }
    g_plugin_count = 0;
}

/*
 * Find a loaded plugin by name.
 */
const SensorPlugin *plugin_find(const char *name) {
    for (int i = 0; i < g_plugin_count; i++) {
        if (strcmp(g_plugins[i].plugin->name, name) == 0)
            return g_plugins[i].plugin;
    }
    return NULL;
}

int   plugin_count(void)          { return g_plugin_count; }
const SensorPlugin *plugin_get(int i) {
    if (i < 0 || i >= g_plugin_count) return NULL;
    return g_plugins[i].plugin;
}
```

---

## The core application

```c
/* main.c */
#include <stdio.h>
#include <unistd.h>
#include <signal.h>
#include "plugin_api.h"
#include "plugin_loader.h"
#include "log.h"

static volatile sig_atomic_t g_quit = 0;
static void handle_quit(int s) { (void)s; g_quit = 1; }

int main(int argc, char *argv[]) {
    const char *plugin_dir = argc > 1 ? argv[1] : "./plugins";

    struct sigaction sa = { .sa_handler = handle_quit,
                            .sa_flags   = SA_RESTART };
    sigemptyset(&sa.sa_mask);
    sigaction(SIGTERM, &sa, NULL);
    sigaction(SIGINT,  &sa, NULL);

    /* Discover and load plugins */
    int n = plugin_load_dir(plugin_dir);
    if (n <= 0) {
        LOG_ERROR("no plugins found in %s", plugin_dir);
        return 1;
    }

    LOG_INFO("%d sensor plugins active", n);

    /* Run self-tests on plugins that support it */
    for (int i = 0; i < plugin_count(); i++) {
        const SensorPlugin *p = plugin_get(i);
        if (p->self_test) {
            char report[256];
            p->self_test(report, sizeof(report));
            LOG_INFO("self_test [%s]: %s", p->name, report);
        }
    }

    /* Main polling loop */
    int tick = 0;
    while (!g_quit) {
        for (int i = 0; i < plugin_count(); i++) {
            const SensorPlugin *p = plugin_get(i);
            if (!p->is_available()) continue;

            float   val;
            uint8_t dev_id;
            if (p->read(&val, &dev_id) == 0) {
                printf("[tick %4d] %s device=%u value=%.3f\n",
                       tick, p->name, dev_id, val);
            } else {
                LOG_WARN("read failed: %s", p->name);
            }
        }
        tick++;
        sleep(1);
    }

    plugin_unload_all();
    LOG_INFO("done");
    return 0;
}
```

---

## Build system for plugins

```makefile
CC      = gcc
CFLAGS  = -Wall -Wextra -std=c11 -Iinclude
LDFLAGS = -Wl,-rpath,'$$ORIGIN/plugins'

# Core binary
core: main.o plugin_loader.o log.o errors.o
	$(CC) $(CFLAGS) -o $@ $^ -ldl

# Each plugin is an independent .so
plugins/temperature.so: temperature_plugin.c include/plugin_api.h
	@mkdir -p plugins
	$(CC) $(CFLAGS) -fPIC -fvisibility=hidden \
	    -shared -o $@ $

plugins/humidity.so: humidity_plugin.c include/plugin_api.h
	@mkdir -p plugins
	$(CC) $(CFLAGS) -fPIC -fvisibility=hidden \
	    -shared -o $@ $

plugins: plugins/temperature.so plugins/humidity.so

all: core plugins

clean:
	rm -f core *.o plugins/*.so

.PHONY: all plugins clean
```

Run it:

```bash
make all
./core ./plugins
```

Add a new sensor type: write a new `_plugin.c`, add one line to the Makefile, `make plugins`. The core binary is untouched.

---

## `__attribute__((constructor))` and `__attribute__((destructor))`

For plugins that need to run code when the `.so` is loaded or unloaded — before `init()` is even called:

```c
/* Runs automatically when dlopen() loads this .so */
__attribute__((constructor))
static void on_load(void) {
    /* Register with a global table, initialise libusb, etc. */
    /* Runs before main() if linked at startup,
       or immediately after dlopen() if loaded at runtime */
}

/* Runs automatically when dlclose() unloads this .so */
__attribute__((destructor))
static void on_unload(void) {
    /* Clean up resources unconditionally */
}
```

This is useful when your plugin wraps a C library that requires global init/cleanup (`libusb_init`, `curl_global_init`) — you can guarantee it happens regardless of whether the caller remembers to call `init()`.

---

## Day 23 exercise

1. Build the complete plugin system from the lesson. Verify `./core ./plugins` loads both plugins, runs self-tests, and polls both sensors. Add a third plugin — `pressure_plugin.c` — that simulates a barometric pressure sensor. Confirm the core picks it up without any recompilation.
    
2. Add API version mismatch handling: compile `temperature_plugin.c` with `PLUGIN_API_VERSION` set to 99. Verify the loader rejects it with a clear error message and continues loading the remaining plugins.
    
3. Add a `configure` implementation to `temperature_plugin.c` that accepts a `"device"` key to set the device path, and a `"interval_ms"` key to set a per-plugin read interval. Call `p->configure("device", "/dev/ttyUSB1")` from the core after loading and verify the plugin stores the value.
    
4. Extend `plugin_load_dir` to watch the plugins directory for new `.so` files using `inotify`. When a new `.so` appears, load it automatically. When an existing `.so` is replaced (a `IN_CLOSE_WRITE` event on an existing file), unload the old version and load the new one. This is hot-reload — common in long-running IoT daemons.
    

Day 24 covers debugging with GDB — breakpoints, watchpoints, stack inspection, memory examination, and post-mortem debugging with core dumps.