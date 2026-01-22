# Falael Fast Resilient File Cache for OpenCart - File Cache Driver Drop-in Replacement

## Version

**v1.1b**

## Overview

### Audience

- **OpenCart Administrators** managing high-traffic stores or large product catalogs (5,000+ items) who are experiencing "Server Meltdown" scenarios during bot crawls or high-concurrency events.

- **OpenCart Developers** seeking a fault-tolerant fast alternative to the stock file cache that eliminates race conditions and data corruption without the overhead of moving to Redis or Memcached.

### Motivation

OpenCart's stock file cache engine utilizes `glob()` for file lookups, which leads to O(N) search complexity. Under high-concurrency and large catalog scenarios, this causes significant I/O wait-states and CPU saturation during directory scans. Additionally, the lack of atomic locking mechanisms frequently results in thundering herd effects, cascade of failing `unlink`-s and potential data corruption when multiple worker processes attempt to invalidate and rebuild the same cache key simultaneously. This engine replacement aims to fully mitigate these deficits w/o changing the transactionless interface of OpenCart caching engines.

### Abstract

The Falael Fast Resilient File Cache is a specialized drop-in replacement for the default OpenCart 4.x family of file cache drivers. It replaces the stock O(N) `glob()`-based lookups with an O(1) deterministic structured path model and reduces the happy `get` scenario operations (return of a valid cache value) to the absolute minimum, achieving an average 4x increase in OpenCart overall page serving speed. Built for high-concurrency environments, it eliminates common issues like race conditions, deadlocks, and data corruption through a combination of advisory rebuild locks and invalidation tokens. The driver remains fully compatible with the standard transactionless OpenCart interface while providing fault-tolerant behavior even during unsynchronized external filesystem wipes (`rm -rf cache/*`).

Under rebuild or invalidation storms, the driver prioritizes availability of the most recent known truth over request timeout by serving stale L1 data while L2 is being rebuilt; once any rebuild completes, L2 immediately becomes the new source of truth for all subsequent requests, preventing timeout cascades that would occur if all concurrent readers simultaneously hammered the database and rendering layer while also triggering chaotic parallel GC operations that could delete freshly-rebuilt cache files before they're read, causing further rebuild cascades.

This driver strategically treats all potential exceptional workflows as regular, making for a fully fault-tolerant cache management.

Versions:

- The main OpenCart 4.x version is available under `oc_v4` repo dir.
- An untested OpenCart 3.x compatible source port is available under `oc_v3` repo dir.

### Compatibility

The Falael Fast Resilient File Cache is designed as a drop-in replacement for OpenCart 4.x. While optimized and tested on the latest versions, its design aims for broad compatibility across the 4.x branch.

- OpenCart 4.1.0.3: Fully tested and verified. This version historically suffers from severe performance regressions under high catalog counts, which this driver specifically resolves.

- OpenCart 4.0.2.x: Compatible. It provides the atomic locking and data integrity protections that were absent in these versions' transition away from strict `flock` implementations.

- OpenCart 4.0.0.0 (Beta & Release): Architecturally compatible. It replaces the inefficient constructor-based file scanning found in these early releases with efficient on-demand path resolution.

- OpenCart 3.x (Alpha/**Untested**): Code migrated for lower versions of PHP compatible with OpenCart 3.x. Not tested, might need adjustments.

Tested in production: Currently active on a live production web store (400-1200 requests per minute) with ~9000 products in ~350 categories, consistently managing concurrent requests without service degradation. Further testing in production enviroments is needed. Please add an issue on GitHub if you have live site performance insights or if you encounter any problems.

## Features

> All listed feats are in place to ensure non-transactional, fully fault-tolerant behavior of the cache.

- **O(1) Deterministic Path Resolution with Directory Structure Preservation**  
  - Challenge: Stock OpenCart uses `glob()` for cache lookups, causing O(N) directory scans that saturate I/O under large catalogs.  
  - Solution: Direct path computation from cache key structure; directory preservation during invalidation eliminates filesystem metadata churn.

- **Two-Tier Caching Strategy (L2/L1)**  
  - Challenge: Cache invalidation typically forces all readers to wait for rebuild, causing latency spikes.  
  - Solution: Fresh timestamped files (L2) with stale backup copies (L1) enable fast `get` reads during invalidate/dropped rebuild operations.

- **Delete-Over-Write Priority Locking**  
  - Challenge: Concurrent writes during invalidation can corrupt cache state or resurrect stale data to L2.  
  - Solution: Three-tier lock hierarchy (Delete > Write > Rebuild) ensures invalidation operations always succeed and cancel conflicting writes.

- **Invalidation Token System (Stale Write Prevention)**  
  - Challenge: Writers pass optimistic delete check, then block on write lock, when a delete operation runs to completion, with write resuming with stale context effectively corrupting the L2 cache with invalid data.  
  - Solution: `mtime`-based versioning on delete lock file; writers capture token before lock acquisition, validate after acquiring lock to detect competing invalidations and giving up on positive detection.

- **Advisory Rebuild Lock with Rate Limiting**  
  - Challenge: Thundering herd on cache miss causes hundreds of simultaneous expensive rebuild operations.  
  - Solution: 20ms forced hold time prevents stampedes; losers fall back to L1 instead of blocking; simultaneous rebuilds still possible (no way to prevent w/o cache interface change) but limited to one per 20ms.

- **L2 -> L1 Swap on Delete (Graceful Degradation)**  
  - Challenge: Cache invalidation destroys data, forcing all readers to block on rebuild, also during cache invalidation.
  - Solution: Invalidation promotes newest L2 to L1 instead of destruction; maintains stale data availability during rebuild/invalidation storms by serving L1 stale data during invalidation and `get`-s dropped due to rebuild rate limiting.

- **Zombie Promotion Garbage Collection**  
  - Challenge: Aggressive GC deletion causes cache misses and rebuild storms under high load.  
  - Solution: Expired L2 files converted to L1 rather than deleted; preserves and serves fallback data under load even during heavy GC by serving L1.

- **Transactionless Interface with ACID-like Consistency**  
  - Challenge: OpenCart's cache interface provides no transaction support or lock handles for atomic cache rebuilds.  
  - Solution: Optimistic locking and token validation achieve write consistency without long-held locks or API changes.

- **Fault Tolerance Under Filesystem Chaos**  
  - Challenge: External filesystem operations (`rm -rf cache/*`) during active cache use might cause cache data corruption.
  - Solution: Treat all potential exceptional workflows as regular, resulting in directory and file structure self-healing. 

- **Per-Bucket Lock Granularity**  
  - Challenge: Hierarchical key/directory locking (matching OpenCart's nested key structure) is prohibitively I/O-expensive; global locks serialize all operations. 
  - Solution: Locks scoped to first key segment (bucket) (e.g., `product.*`, `category.*`) serialize only write/delete operations per bucket; the most common-case L2 hit path remains completely lock-free, while cache-miss rebuilds use rate-limited NULL returns with L1 fallback instead of blocking, eliminating hierarchical locking overhead and complexity while preventing cross-bucket contention. 
  
- **Time-Gated Atomic Garbage Collection with Configurable Windows**  
  - Challenge: Frequent GC runs cause I/O spikes, especially when using probabilistic approach (1 of 100 requests have the chance to cause GC) - in high load scenarios request quantity per minute increases, effectively increasing the absolute density of GCs in a unit of time, also leading to concurrent GC runs; concurrent GC processes might corrupt cache state and cause a cascade of failing `unlink`-s. 
  - Solution: `flock()`-controlled single-process GC with configurable interval and time-of-day restrictions.

- **Cache Miss vs Cached Empty Array Detection Bugfix**  
  - Challenge: OpenCart's cache interface doesn't distinguish cached empty arrays (`get()` returns `[]`) from cache miss (`get()` also returns `[]`).  
  - Solution: Returns explicit NULL on cache miss instead of empty array; backwards compatible with existing `if(!$cached_data)` checks in OpenCart core and plugins while enabling precise `if($cached_data === null)` cache miss detection for codebases that adopt the fix.
  
- **Non-Blocking Optimistic Gatekeeper Checks**
  - Challenge: Lock acquisition attempts block even when operation will inevitably fail.
  - Solution: Fast pre-lock validations reduce contention; graceful degradation on lock acquisition failure.

- **Cross-Platform File Locking (Windows/Linux)**  
  - Challenge: Windows file locking semantics differ from POSIX; file-in-use errors break cache operations.  
  - Solution: Retry logic and error suppression handle Windows file-in-use conflicts and lock acquisition races.

- **Threshold-Based Empty Directory Pruning**  
  - Challenge: Empty directory cleanup adds unnecessary filesystem overhead during normal operation.  
  - Solution: Cleanup activated only when bucket exceeds 15,000 files; avoids frequent unnecessary filesystem operations.

- **Detailed Debug Logging and Test Condition Simulation**  
  - Challenge: File cache race conditions and lock contention issues are non-deterministic and difficult to reproduce or diagnose in production environments.  
  - Solution: Optional debug mode with millisecond-precision logging of all lock acquisitions, state transitions, and cache operations; test mode hooks enable deterministic simulation of race conditions (GC forcing, operation lag injection) for validation.
  
- **Comprehensive Concurrency Stress Testing**  
  - Challenge: File cache implementations fail unpredictably under race conditions that unit tests cannot expose.  
  - Solution: 14-test suite validates thundering herd, race conditions, delete priority, and fault tolerance under 100+ worker load (see below).

## Installation

### Drop-In Replacement

1. **Back up the existing cache driver:**

   ```bash
   cp system/library/cache/file.php <oc_root>/system/library/cache/file.php.backup
   ```

2. **Copy the Falael Fast Resilient File Cache over your OpenCart installation:**

   ```bash
   cp -r system/* <oc_root>/system/
   cp test_runner.php <oc_root>/
   ```
   
   Or on Windows:
   
   ```cmd
   xcopy /E /Y system\* <oc_root>\system\
   copy /Y <oc_root>\test_runner.php <oc_root>\
   ```

3. **Clear existing cache (optional but recommended):**

   ```bash
   rm -rf <oc_root>/system/storage/cache/*
   ```
   
   Or on Windows:
   
   ```cmd
   del /Q /S <oc_root>\system\storage\cache\*
   ```

No configuration changes or additional dependencies required. The driver is fully compatible with OpenCart's existing cache interface.

**Note:** The `test_runner.php` file is optional and only needed if you want to run the concurrency test suite to verify the installation.

### Recommended Optimizations

#### 1. Leverage NULL Cache Miss Detection

**Current OpenCart pattern:**

```php
$data = $this->cache->get('product.' . $product_id);
if (!$data) {
    $data = $this->model_catalog_product->getProduct($product_id);
    $this->cache->set('product.' . $product_id, $data);
}
```

**Optimized pattern (precise cache miss detection):**

```php
$data = $this->cache->get('product.' . $product_id);
if ($data === null) {
    $data = $this->model_catalog_product->getProduct($product_id);
    $this->cache->set('product.' . $product_id, $data);
}
```

This allows caching of empty arrays, false values, and other falsy data without triggering unnecessary rebuilds.

#### 2. Implement Product-Level Cache Invalidation

**Background:**

OpenCart's default `product` bucket stores all product-related cache (category lists, bestsellers, related products) but not individual product pages. Caching product page data significantly improves site responsiveness during episoeds of high contention. By default any admin product change invalidates the entire 'product' bucket, causing unnecessary cache misses across the store.

**Solution: Separate Bucket for Product Detail Pages**

Create a dedicated `product_by_id` bucket for individual product page caching with granular invalidation.

**Step 1: Implement Product Page Caching**

Locate your product detail page controller (typically `catalog/controller/product/product.php`) and wrap the rendering logic:

```php
// Example location: catalog/controller/product/product.php
public function index(): void {
    $product_id = (int)$this->request->get['product_id'];
    
    // Attempt cache read
    $data = $this->cache->get('product_by_id.' . $product_id);
    
    if ($data === null) {
        // Cache miss - rebuild page data
        $data = $this->buildProductData($product_id); // Your existing rendering logic
        
        // Cache the result
        $this->cache->set('product_by_id.' . $product_id, $data);
    }
    
    // Render using cached data
    $this->response->setOutput($this->load->view('product/product', $data));
}
```

**Step 2: Add Granular Invalidation in Admin**

Modify `admin/model/catalog/product.php`:

```php
// In editProduct() method (after product update)
$this->cache->delete('product_by_id.' . (int)$product_id); // Single product page only
$this->cache->delete('product');                            // Product lists (categories, bestsellers, etc.)
```

```php
// In deleteProduct() method
$this->cache->delete('__PURGE__product_by_id.' . (int)$product_id);	//	the `'__PURGE__'` prefix forces `$this->cache->delete` to delete both L1 and L2 cache entries while still preserving directories
$this->cache->delete('product');
```

**Step 3: Add Stock-Based Invalidation in Checkout**

Modify `catalog/model/checkout/order.php` in the `addHistory()` method (after stock updates):

```php
// Around line ~500, after stock subtraction/addition
foreach ($order_products as $order_product) {
    $this->cache->delete('product_by_id.' . (int)$order_product['product_id']);
}
$this->cache->delete('product'); // Lists may show stock status indicators
```

**Impact:**

- Product page edits: Only invalidate 1 key instead of all products
- Order placement: Only invalidate affected product pages (typically 1-5 keys) instead of all products

**Files To Modify:**
- `catalog/controller/product/product.php` (or equivalent product detail controller)
- `admin/model/catalog/product.php`
- `catalog/model/checkout/order.php`

## Tests

The `test_runner.php` provides a concurrency test suite that validates the cache driver's behavior under extreme load and race conditions.

- **Test [1] Framework Verification (Ping)**  
  - Purpose: Test system test - validates the test orchestrator's ability to spawn worker processes, capture logs, and detect completion signals.
  - On success: All workers should report "Alive." and "DONE." - workers that hang or fail to report indicate indicate infrastructure issues, not cache bugs.

- **Test [2] Thundering Herd (Stale Fallback)**  
  - Purpose: Validates rebuild lock rate limiting and L1 fallback behavior when hundreds of concurrent requests hit a missing L2 cache.  
  - On success: A couple of workers should report "WINNER! Got NULL (Must Rebuild)" with rebuild lock held; all other workers should report "Got Data: L1 (Fallback)" within milliseconds without blocking.

- **Test [3] Active Delete Block (Fast Fail)**  
  - Purpose: Validates Delete-Over-Write Priority - `set()` operations must abort immediately when delete lock is held.  
  - On success: Victim worker should report "SUCCESS: Write aborted (No L2 file found)" within ~100ms; if L2 file exists, delete priority enforcement failed.

- **Test [4] Concurrent Reads: Warm L2 (Fresh)**  
  - Purpose: Validates lock-free read performance on fresh cache hits under high concurrency (20 workers).  
  - On success: All workers should report "HIT L2 (Fresh)" with sub-millisecond latency; any NULL returns or L1 fallbacks indicate read path regression.

- **Test [5] Concurrent Reads: Warm L1 (Stale)**  
  - Purpose: Validates L1 fallback behavior and rebuild lock acquisition under concurrent cache-miss load.  
  - On success: 1-2 workers report "MISS (Got Lock) - I am the Rebuilder"; remaining workers report "HIT L1 (Fallback)"; no workers should block waiting for rebuild completion.

- **Test [6] Concurrent Reads: Cold Cache (Total Miss)**  
  - Purpose: Validates behavior when cache is completely empty under concurrent access.  
  - On success: All workers should report "MISS (NULL)" without errors or hangs; validates graceful handling of non-existent cache state.

- **Test [7] Rebuild Cycle vs Standard Delete (L1 Fallback)**  
  - Purpose: Validates that cyclic get/rebuild operations can read stale L1 data during active delete operations.  
  - On success: Cycler should report continuous "READ: SUCCESS (Found Data)" during deleter's 4-second lock hold, demonstrating L1 fallback allows reads to continue during invalidation without blocking or rebuilding and writing to cache.
  
- **Test [8] Rebuild Cycle vs Total Wipeout (Write Block)**  
  - Purpose: Validates behavior when both L1 and L2 are destroyed during active rebuild cycles (abnormal condition).  
  - On success: Cycler should report "READ: NULL" and "WRITE: FAILED" during wipeout's 4-second lock hold; demonstrates total cache loss scenario without deadlocks.

- **Test [9] Chaos Test: Terminal Nuke vs Busy Workers**  
  - Purpose: Validates fault tolerance when directory structure is violently destroyed (`rm -rf`) during active cache operations.  
  - On success: Busy workers report continuous HITs and UPDATEs (on Windows) or MISSes (on linux) (logging every 5th operation) without fatal errors or hangs; nuker reports "NUKE SUCCESSFUL" or "NUKE PARTIAL" (Windows file locks); workers complete without crashes, demonstrating system continues operating during and after filesystem chaos.

- **Test [10] The Sniper: Stale Write Race Condition**  
  - Purpose: Validates invalidation token system prevents stale writes when delete() completes between optimistic check and lock acquisition.  
  - On success: Laggy writer should report "SUCCESS: Write was ABORTED (Token mismatch detected)"; if stale data file exists, race condition mitigation failed.

- **Test [11] Busy GC Trigger (Zombie Promotion Verify)**  
  - Purpose: Validates garbage collector's zombie promotion logic - expired L2 files should be demoted to L1, not deleted.  
  - On success: Watcher should report "SUCCESS: L2 Gone. L1 Found! (Zombie Promotion Logic Verified)" within 6 seconds; if L2 vanishes without L1 creation, GC logic failed.

- **Test [12] The Undead File (GC vs Delete Locking)**  
  - Purpose: Validates that GC operations and delete operations coordinate properly via locking to prevent file resurrection.  
  - On success: Destroyer should report "SUCCESS: All L2 targets successfully demoted to L1" with zero failures; failures indicate lock coordination breakdown between GC and delete operations.

- **Test [13] SYSTEM MELTDOWN (High CPU/IO Stress Test)**  
  - Purpose: Validates cache driver stability under sustained high-load conditions (20 renderers + 2 chaos monkeys, 240 seconds).  
  - On success: All renderer workers should report "DONE. Total Pages: [count]" without fatal errors; chaos monkeys should complete GC/delete cycles; monitors for memory leaks, lock starvation, and filesystem corruption.

- **Test [14] BENCHMARK: Latency & Throughput (100 Workers)**  
  - Purpose: Measures production-representative performance metrics (noop latency replaced with CPU-intensive loops) under 100 concurrent workers with 5 chaos monkeys over 240 seconds.  
  - On success: Aggregated report shows min/avg/max latency for page build time and cache operations; validates performance does not degrade catastrophically under sustained load; typical results: very low mins (0.1-2ms), very high maxes (10-15s) averages much closer to mins than to maxes (300ms page builds, 35ms cache ops).
    
