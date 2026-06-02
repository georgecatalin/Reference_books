## Context bootstrap (use once per project)

Prompt:

You are reviewing C code for memory/resource leaks and ownership bugs.

Rules: identify every heap allocation and resource acquisition (malloc/calloc/realloc/strdup/asprintf/getifaddrs/open/socket/fd/FILE*/pthread objects), and map ownership from allocator to final free/close.

Output must include:

1. an “Ownership & Lifetime Rules” section (what functions return owned pointers, borrowed pointers, static buffers, etc.)
2. a table: allocator → owner → who must free → where freed (function + code path)
3. a list of definite leaks, possible leaks, double-free risks, use-after-free risks, and “process-lifetime caches”.  
    Ask clarifying questions when ownership cannot be determined.  
    Here is the code (I will paste multiple files). Start by extracting the list of allocators and resource-acquiring calls.

Then paste the relevant files.

## Project-wide allocation inventory (find all allocators)

Prompt:

Scan the following C code and produce an “Allocation & Resource Inventory”.

List every place that calls malloc/calloc/realloc/strdup/asprintf as well as resource acquisitions like getifaddrs/fopen/open/socket/accept/pthread_create/sem_init.

For each, record: file, function, what is allocated/acquired, and what variable stores the handle/pointer.

Do not propose fixes yet—just inventory and categorize by resource type.

Paste code.

## Ownership table (allocator → owner → who must free → where freed)

Prompt:

Build an ownership table for the following code.

For each allocation/acquisition, fill:

- allocator/acquirer API (e.g., malloc, strdup, getifaddrs, socket)
- allocated object / resource handle
- initial owner (the function that receives the pointer/handle)
- transfer of ownership (which function returns/passes it onward)
- final owner (who is responsible)
- who must free/close
- where freed/closed (exact function and which control-flow paths)  
    If unknown, mark as UNKNOWN and ask what the contract is.

Paste code.

## Function-by-function contract extraction (“does caller free?”)

Prompt:

For each function defined below, infer and state a precise ownership contract:

- what it returns (owned heap pointer vs borrowed pointer vs static)
- whether caller must free and with what function
- whether it stores pointers globally/static and for how long  
    Provide a bullet list of contracts and flag any ambiguous/unsafe contracts.

Paste function implementations and headers.

## Path-sensitive leak audit (all returns, breaks, gotos)

Prompt:

Perform a path-sensitive leak audit for these functions.

For each function: enumerate all exit paths (early return, error return, success return).

For each path, list resources that were acquired and confirm they are released.

If not released, label as “LEAK on path X” and propose the minimal fix (prefer a single cleanup label).

Include heap pointers, file descriptors, getifaddrs memory, mutexes, semaphores, sockets, FILE*.

Paste 1–3 functions at a time.

##  “Suspicious pattern” prompts (common C leak sources)

### Returned pointer ownership audit

Prompt:

Identify every function that returns a char* or pointer-like handle. For each, determine whether it returns:

(1) malloc’d memory, (2) static buffer, (3) pointer into an input buffer, or (4) pointer owned by another module.

Then list all call sites and verify correct freeing (or non-freeing).

Output mismatches (e.g., freeing static, not freeing heap, double free).

### Global/static pointer audit

Prompt:

Audit all global/static pointers and caches. For each, explain:

- where it is assigned
- whether it points to heap memory
- when (if ever) it is freed
- whether it can be overwritten (leak)
- thread-safety risks  
    Recommend whether it should be freed at shutdown or refreshed safely.

### Error-path audit

Prompt:

Focus only on error paths (if/return on errors).

For each error path, list what has already been allocated/acquired and whether it gets freed/closed before returning.

Output a list of “error-path leaks”.

## Thread-safety + leak interaction (race-created leaks)

Prompt:

Analyze this code for thread-safety issues that can cause memory leaks or use-after-free (e.g., “init once” caches without locks, global buffers, static state).

Provide:

- the race scenario
- what gets leaked or corrupted
- a minimal fix using pthread_once or mutex
- whether the fix changes ownership rules

Paste the cache-related code and where it’s called.

## Resource-type specific prompts

### getifaddrs and networking resources

Prompt:

Audit all usage of getifaddrs / freeifaddrs, inet_ntop, inet_pton, sockets, and address structs.

Confirm freeifaddrs is called on all paths.

Confirm malloc allocations based on interface count are freed.

Identify any stale cache issues and propose a refresh strategy that does not leak.

### File descriptors / sockets / accept loop

Prompt:

Audit file descriptor lifecycle in this server code.

For each fd created (socket/accept/pipe endpoints), record: where created, stored, when closed, and whether any fd can leak on error paths or disconnect paths.

### Produce “fix plan” with minimal invasive changes

Prompt:

Based on the leak/ownership findings, propose a fix plan with minimal changes:

- list each change as (file, function, patch intent)
- specify exact resources to free/close and where
- ensure no double free
- preserve existing APIs if possible  
    Include a final updated ownership table after the proposed fixes.

### Ask the AI to generate a checklist for future code

Prompt:

Generate a project-specific “Memory Ownership Checklist” from this codebase.

Include rules like: “Functions named get_* return owned memory and must be freed with free_*”, or “authorize_cmd_* returns static buffer” etc.

Include do/don’t examples extracted from the code.

### Prompt that forces a strict table output (your requested format)

Prompt:

Produce ONLY the ownership table in YAML with columns:

- allocator
- allocated_resource
- initial_owner_function
- stored_in (variable/global/static)
- ownership_transfer (call chain)
- final_owner
- must_free_or_close (yes/no + function)
- freed_in (function + path)
- leak_risk (none / possible / definite)  
    If any field is unknown, write UNKNOWN and ask a question after the table.

#### Tips to get high-quality results

- Paste headers too: ownership contracts often live in comments/types.
- Audit in small chunks (1–3 functions) for path accuracy.
- Tell the AI whether you run single-threaded or multi-threaded.
- Explicitly state contracts you already know (e.g., “authorize_cmd_* returns malloc’d string and caller must free”).

If you want, paste network_utilities.h plus the implementations of the authorize_cmd_* functions used by TYRESETALIAS* and BLANKETSERIALS?, and I’ll generate the ownership table for those call chains.