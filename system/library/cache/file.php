<?php
namespace Opencart\System\Library\Cache;

/**
 * Class File - Falael Fast Resilient File Cache v1.0b
 *
 * @package Opencart\System\Library\Cache
 */
class File {
    /**
     * @var int Default cache expiry in seconds
     */
    private int $expire;
    
    /**
     * @var string Cache directory
     */
    private string $cache_dir;
    
    /**
     * @var BucketLock Lock manager
     */
    private BucketLock $lock;
    
    /**
     * @var string|null Test mode flag
     */
    private ?string $test_mode = null;

    /**
     * @var bool Debug flag for diagnostic logging
     */
    private const DEBUG_MODE = false;

    // --- GC CONFIGURATION PROPERTIES (Initialized from constants, overridable for tests) ---

    /**
     * @var int Minimum seconds between GC runs
     */
    private int $gc_interval;

    /**
     * @var int Start hour for GC (0 = Midnight)
     */
    private int $gc_start_hour;

    /**
     * @var int End hour for GC (6 = 6 AM)
     */
    private int $gc_end_hour;
    
    // --- CONSTANTS ---

    /**
     * Name of write lock file
     */
    private const LOCK_FILE_WRITE = 'lock-write';
    
    /**
     * Name of delete lock file
     */
    private const LOCK_FILE_DELETE = 'lock-delete';
    
    /**
     * Name of rebuild cache value lock file
     */
    private const LOCK_FILE_REBUILD = 'lock-rebuild';

    /**
     * File to store the last GC run timestamp (cache-global)
     */
    private const GC_CONTROL_FILE = 'gc-control';

    /**
     * Default Minimum seconds between GC runs (12 hours)
     */
    private const DEFAULT_GC_INTERVAL = 43200; 

    /**
     * Default Start hour for GC (0 = Midnight)
     */
    private const DEFAULT_GC_START_HOUR = 0;

    /**
     * Default End hour for GC (6 = 6 AM)
     */
    private const DEFAULT_GC_END_HOUR = 6;
    
    /**
     * When expire equals this value, skip TTL expiration entirely
     */
    private const NO_EXPIRATION_THRESHOLD_SECONDS = 3601;
    
    /**
     * Grace delay before rebuild operation (microseconds) - imposes a max rebuild rate per bucket (rebuild lock always holds for only this value while still allowing for mutliple rebuilds; opencart cache interface doesn't allow for holding rebuild lock during rebuild)
     */
    private const GET_GRACE_DELAY_US = 20000;

    /**
     * Timeout for acquiring rebuild lock (ms)
     */
    private const REBUILD_LOCK_TIMEOUT_MS = 10;
    
    /**
     * Timeout for acquiring write lock (ms)
     */
    private const WRITE_LOCK_TIMEOUT_MS = 100;
    
    /**
     * Timeout for acquiring delete lock (ms)
     */
    private const DELETE_LOCK_TIMEOUT_MS = 60000;
    
    /**
     * Max stale files before cleanup in set()
     */
    private const MAX_STALE_FILES = 1;
    
    /**
     * L1 filename prefix
     */
    private const L1_PREFIX = 'l1-';

    /**
     * Threshold of files in a bucket before enabling empty directory pruning
     */
    private const DIR_PRUNE_THRESHOLD = 15000;

    /**
     * Constructor
     *
     * @param int $expire Default expiry time in seconds
     * @param string|null $test_mode Optional test mode flag
     */
    public function __construct(int $expire = 3600, string $test_mode = null) {
        $this->expire = $expire;
        $this->cache_dir = rtrim(DIR_CACHE, '/') . '/';
        $this->lock = new BucketLock($this->cache_dir, self::LOCK_FILE_WRITE, self::LOCK_FILE_DELETE, self::LOCK_FILE_REBUILD);
        $this->test_mode = $test_mode;

        // Initialize GC settings from constants
        $this->gc_interval = self::DEFAULT_GC_INTERVAL;
        $this->gc_start_hour = self::DEFAULT_GC_START_HOUR;
        $this->gc_end_hour = self::DEFAULT_GC_END_HOUR;

        // Apply Test Overrides if specific mode is set
        if ($this->test_mode === 'force_gc') {
            $this->gc_interval = 0; // Run immediately (0 seconds since last run)
            $this->gc_start_hour = 0; // Allow running now
            $this->gc_end_hour = 24;  // Allow running anytime
        }

        $this->log("DRIVER LOADED | Dir: " . $this->cache_dir);
    }
    
    /**
     * DIAGNOSTIC LOGGING
     */
    private function log(string $msg): void {
        if (!self::DEBUG_MODE) {
            return;
        }
        $path = defined('DIR_LOGS') ? DIR_LOGS . 'cache_debug.log' : __DIR__ . '/cache_debug.log';
        @file_put_contents($path, date('H:i:s.') . sprintf('%03d', (int)(microtime(true) * 1000) % 1000) . " [" . getmypid() . "] | " . $msg . "\n", FILE_APPEND);
    }
    
    /**
     * Extract bucket name from key (first segment)
     *
     * @param string $key Cache key like "product.123"
     * @return string Bucket name like "product"
     */
    private function getBucket(string $key): string {
        $safe_key = preg_replace('/[^A-Z0-9\._-]/i', '', $key);
        $segments = explode('.', $safe_key);
        return $segments[0];
    }
    
    /**
     * Get directory path for a key
     *
     * @param string $key Cache key
     * @return string Full path to data directory
     */
    private function getDataDir(string $key): string {
        $safe_key = preg_replace('/[^A-Z0-9\._-]/i', '', $key);
        $segments = explode('.', $safe_key);
        return $this->cache_dir . implode('/', $segments) . '/';
    }

    /**
     * Get cached value
     *
     * @param string $key Cache key
     * @return mixed Cached value or empty array if not found
     */
    public function get(string $key): mixed {
        $this->log("GET [" . $key . "]");
        try {
            $dir = $this->getDataDir($key);
            $bucket = $this->getBucket($key);
            
            // Try read L2
            $l2_data = $this->readL2($dir);
            if ($l2_data !== null) {
                $this->log("  -> HIT L2");
                return $l2_data;
            }
            
            // L2 missing - try to acquire rebuild lock
            // the main purpose if this lock is
            // 1. If delete() is running, it holds this lock to prevent rebuild storms while cache is being invalidated. We will fail to acquire it here, allowing us to fall through to L1.
            // 2. in ultra-high load scenarios when multiple cache get requests per bucket are happening within a single self::GET_GRACE_DELAY_US interval, while still allowing for mutliple nearly simultaneous rebuilds; opencart cache interface doesn't allow for holding rebuild lock during rebuild
            $this->log("  -> L2 MISS, attempting rebuild lock");
            if ($this->lock->acquireRebuild($bucket, self::REBUILD_LOCK_TIMEOUT_MS)) {
                $this->log("  -> Acquired Rebuild Lock (Return NULL)");
                // oc has no interface allowing from locking on full rebuild operation; waiting before releasing the lock to impose a short rebuild rate limit (one rebuild every self::GET_GRACE_DELAY_US)
                usleep(self::GET_GRACE_DELAY_US);
                // Got lock - we do the rebuild
                $this->lock->releaseRebuild();
                $this->log("  -> Released Rebuild Lock");
                // Return empty - caller will rebuild and call set()
                return null;
            }
            
            // No lock - enormous get load (multiple get requests on the same bukcet within 20ms) or delete op is in progress
            //          - doesn't lock during rebuild, will initiate multiple rebuilds on the same bukcet 
            // Try L1
            $this->log("  -> Failed rebuild lock, trying L1");
            $l1_data = $this->readL1($dir);
            if ($l1_data !== null) {
                $this->log("  -> HIT L1 (Fallback)");
                return $l1_data;
            }
            
            // No L1 - must rebuild
            $this->log("  -> MISS (no L1 either)");
            return null;
        } catch (\Throwable $e) {
            $this->log("ERROR GET: " . $e->getMessage());
            error_log("CACHE ERROR [get]: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Read newest valid L2 file
     *
     * @param string $dir Data directory
     * @return mixed Data or null if not found/error
     */
    private function readL2(string $dir): mixed {
        if (!is_dir($dir)) {
            return null;
        }
        
        $files = glob($dir . '[0-9]*');
        if (!$files) {
            return null;
        }
        
        $files = array_filter($files, 'is_file');
        if (!$files) {
            return null;
        }
        
        rsort($files);
        
        foreach ($files as $file) {
            $time = (int)basename($file);
            
            // Skip expiry check if no expiration mode
            if ($this->expire != self::NO_EXPIRATION_THRESHOLD_SECONDS) {
                if ($time < time()) {
                    continue;
                }
            }
            
            $content = @file_get_contents($file);
            if ($content === false) {
                continue;
            }
            
            $data = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $data;
            }
        }
        
        return null;
    }
    
    /**
     * Read newest L1 file
     *
     * @param string $dir Data directory
     * @return mixed Data or null if not found/error
     */
    private function readL1(string $dir): mixed {
        if (!is_dir($dir)) {
            return null;
        }
        
        $files = glob($dir . self::L1_PREFIX . '*');
        if (!$files) {
            return null;
        }
        
        $files = array_filter($files, 'is_file');
        if (!$files) {
            return null;
        }
        
        rsort($files);
        
        $content = @file_get_contents($files[0]);
        if ($content === false) {
            return null;
        }
        
        $data = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $data;
        }
        
        return null;
    }

    /**
     * Set cached value (public API)
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int $expire Expiry time in seconds (0 = use default)
     */
    public function set(string $key, mixed $value, int $expire = 0): void {
        $this->log("SET [" . $key . "]");
        try {
            $this->_set($key, $value, $expire);
        } catch (\Throwable $e) {
            $this->log("ERROR SET: " . $e->getMessage());
            error_log("CACHE ERROR [set]: " . $e->getMessage());
        }
    }
    
    /**
     * Set cached value (internal, no grace delay)
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int $expire Expiry time in seconds (0 = use default)
     */
    private function _set(string $key, mixed $value, int $expire = 0): void {
        $bucket = $this->getBucket($key);
        
        // 1. Capture Invalidation Token (Baseline) - MUST BE FIRST
        // We establish the "version" of the bucket we are working with.
        $token_start = $this->lock->getInvalidationToken($bucket);
        $this->log("  -> Token start: " . $token_start);

        // 2. Optimistic Gatekeeper: Check if a delete operation is in progress
        if (!$this->lock->checkDelete($bucket)) {
            $this->log("  -> Aborted: delete in progress (optimistic check)");
            return;
        }

        if ($this->test_mode == 'lag_set_init') sleep(3);

        // 3. Acquire Write Lock
        $this->log("  -> Acquiring write lock...");
        if (!$this->lock->acquireWrite($bucket, self::WRITE_LOCK_TIMEOUT_MS)) {
            $this->log("  -> Aborted: write lock timeout");
            return;
        }
        $this->log("  -> Write lock acquired");
        
        try {
            // 4. Pessimistic Double-Check
            
            // A. Check if Delete Lock is CURRENTLY held
            if (!$this->lock->checkDelete($bucket)) {
                $this->log("  -> Aborted: delete in progress (pessimistic check)");
                return;
            }

            // B. Check if Delete RAN and FINISHED while we waited
            // We compare the current state against our baseline from Step 1.
            $token_now = $this->lock->getInvalidationToken($bucket);
            if ($token_start !== $token_now) {
                $this->log("  -> Aborted: token mismatch (was $token_start, now $token_now)");
                return; // State changed, abort stale write
            }

            $dir = $this->getDataDir($key);
            
            // Ensure directory exists
            if (!is_dir($dir)) {
                if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
                    error_log("CACHE ERROR: Cannot create cache directory: " . $dir);
                    return;
                }
            }
            
            // Cleanup old L2 files if too many
            $files = glob($dir . '[0-9]*');
            if ($files) {
                $files = array_filter($files, 'is_file');
                if (count($files) >= self::MAX_STALE_FILES) {
                    rsort($files);
                    array_shift($files);
                    foreach ($files as $file) {
                        @unlink($file);
                    }
                }
            }
            
            if (!$expire) {
                $expire = $this->expire;
            }
            
            $timestamp = time() + $expire;
            $l2_file = $dir . $timestamp;
            $l1_file = $dir . self::L1_PREFIX . $timestamp;
            $temp_file = $dir . 'tmp_' . getmypid() . '_' . mt_rand();
            
            // Atomic write
            $json = json_encode($value);
            if (file_put_contents($temp_file, $json) === false) {
                error_log("CACHE ERROR: Cannot write temp file: " . $temp_file);
                @unlink($temp_file);
                return;
            }
            
            // Rename to L2
            if (!rename($temp_file, $l2_file)) {
                error_log("CACHE ERROR: Cannot rename to L2: " . $l2_file);
                @unlink($temp_file);
                return;
            }
            
            // Copy to L1
            @copy($l2_file, $l1_file);
            
            $this->log("  -> SET complete: " . $l2_file);
            
        } finally {
            $this->lock->releaseWrite();
            $this->log("  -> Write lock released");
        }
    }

    private function rmrfRecursive(string $dir): void {
		$dir = rtrim($dir, '/') . '/';
		$items = @scandir($dir);
		if (!$items) {
			return;
		}
		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}
			$path = $dir . $item;
			if (is_dir($path)) {
				$this->rmrfRecursive($path);
				@rmdir($path);
			} else {
				@unlink($path);
			}
		}
	}

	/**
     * Delete cached value
     * Implements Delete-Over-Write Priority and L2->L1 Swap
     *
     * @param string $key Cache key
     */
	public function delete(string $key): void {
		if (strpos($key, '__PURGE__') === 0) {
			$clean_key = substr($key, 9);
			$this->purge($clean_key);
			return;
		}
		$this->log("DELETE CALLED [" . $key . "]");
		try {
			// Handle Wildcard (Global Wipe)
			if ($key === '*') {
				$this->log("  -> Global Wipe (*)");
				$items = @scandir($this->cache_dir);
				if ($items) {
					foreach ($items as $item) {
						if ($item === '.' || $item === '..') {
							continue;
						}
						$path = $this->cache_dir . $item;
						if (is_dir($path)) {
							$this->rmrfRecursive($path);
							@rmdir($path);
						} else {
							@unlink($path);
						}
					}
				}
				return;
			}

			$bucket = $this->getBucket($key);
			$this->log("  -> Bucket: " . $bucket);
			
			// Acquire Delete Lock (Exclusive - Blocks new set calls)
			$this->log("  -> Acquiring delete lock...");
			$got_delete = $this->lock->acquireDelete($bucket, self::DELETE_LOCK_TIMEOUT_MS);
			if (!$got_delete) {
				$this->log("  -> FAILED to acquire Delete Lock - proceeding anyway");
				error_log("CACHE WARNING: Could not acquire delete lock, proceeding anyway: " . $key);
			} else {
				$this->log("  -> Delete lock acquired");
			}

			// Mark Invalidation (Update Token)
			// This ensures any waiting sets know they are invalid
			$this->lock->markInvalidation($bucket);
			$this->log("  -> Invalidation marked");
			
			// Acquire Write Lock (Exclusive - Waits for active set ops to finish)
			$this->log("  -> Acquiring write lock...");
		if (!$this->lock->acquireWrite($bucket, self::DELETE_LOCK_TIMEOUT_MS)) {
			$this->log("  -> FAILED to acquire Write Lock (must never happen), aborting");
			if ($got_delete) {
				$this->lock->releaseDelete();
			}
			return;
			}
			$this->log("  -> Write lock acquired");
			
			// Acquire Rebuild Lock (Advisory - Forces Gets to L1)
			// If we get it, great. If not (timeout), we proceed anyway.
			// Holding this ensures that concurrent gets fail to lock rebuild and fallback to L1.
			$this->log("  -> Acquiring rebuild lock...");
			$got_rebuild = $this->lock->acquireRebuild($bucket, self::REBUILD_LOCK_TIMEOUT_MS);
			if ($got_rebuild) {
				$this->log("  -> Rebuild lock acquired");
			} else {
				$this->log("  -> Rebuild lock not acquired (proceeding anyway)");
			}

			try {
				$dir = $this->getDataDir($key);
				
				if (!is_dir($dir)) {
					$this->log("  -> Dir not found: " . $dir);
					return;
				}
				
				// Recursively process files (Swap logic) without destroying dirs
				$this->deleteRecursive($dir);
				
			} finally {
				if ($got_rebuild) {
					$this->lock->releaseRebuild();
					$this->log("  -> Rebuild lock released");
				}
				$this->lock->releaseWrite();
				$this->log("  -> Write lock released");
				if ($got_delete) {
					$this->lock->releaseDelete();
					$this->log("  -> Delete lock released");
				}
			}
		} catch (\Throwable $e) {
			$this->log("ERROR DELETE: " . $e->getMessage());
			error_log("CACHE ERROR [delete]: " . $e->getMessage());
		}
	}    
    /**
     * Recursively process subdirectories and files
     * Implements L2->L1 Swap logic and preserves directory structure
     * Optimized for single-pass scanning.
     *
     * @param string $dir Directory to clean
     */
    private function deleteRecursive(string $dir): void {
        $items = @scandir($dir);
        if (!$items) {
            return;
        }

        $l2_files = [];
        $l1_files = [];
        $subdirs = [];

        // Single Pass Sorting
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || 
                $item === self::LOCK_FILE_WRITE || 
                $item === self::LOCK_FILE_DELETE || 
                $item === self::LOCK_FILE_REBUILD) {
                continue;
            }
            
            $path = $dir . $item;
            
            if (is_dir($path)) {
                $subdirs[] = $path . '/';
            } elseif (is_numeric($item)) {
                $l2_files[] = $path;
            } elseif (strpos($item, self::L1_PREFIX) === 0) {
                $l1_files[] = $path;
            }
        }
        
        $this->log("  -> Processing " . basename($dir) . " L2:" . count($l2_files) . " L1:" . count($l1_files));

        // L2->L1 Swap Logic
        if ($l2_files) {
            // Case A: L2 exists. Promote newest L2 to L1.
            rsort($l2_files);
            $newest_l2 = array_shift($l2_files);
            $timestamp = basename($newest_l2);
            $new_l1 = $dir . self::L1_PREFIX . $timestamp;
            
            // Rename L2 -> L1 with failure check
            if (!@rename($newest_l2, $new_l1)) {
                @unlink($newest_l2);
            } else {
                $this->log("     -> Promoted L2 " . $timestamp . " to L1");
            }
            
            foreach ($l2_files as $f) @unlink($f);
            foreach ($l1_files as $f) @unlink($f);

        } elseif ($l1_files) {
            // Case B: Only L1 exists. Keep newest L1.
            rsort($l1_files);
            array_shift($l1_files); // Keep newest
            $this->log("     -> Kept newest L1");
            foreach ($l1_files as $f) @unlink($f);
        }
        
        // Recurse into subdirectories
        foreach ($subdirs as $subdir) {
            $this->deleteRecursive($subdir);
            // DO NOT rmdir($path) - preserve structure
        }
    }

	public function purge(string $key): void {
		$this->log("PURGE CALLED [" . $key . "]");
		try {
			// Handle Wildcard (Global Wipe)
			if ($key === '*') {
				$this->log("  -> Global Purge (*)");
				$items = @scandir($this->cache_dir);
				if ($items) {
					foreach ($items as $item) {
						if ($item === '.' || $item === '..') continue;
						$path = $this->cache_dir . $item;
						if (is_dir($path)) {
							$this->rmrfRecursive($path);
							@rmdir($path);
						} else {
							@unlink($path);
						}
					}
				}
				return;
			}

			$bucket = $this->getBucket($key);
			$dir = $this->getDataDir($key);
			
			if (!is_dir($dir)) {
				$this->log("  -> Dir not found: " . $dir);
				return;
			}
			
			// Acquire locks (same as delete)
			$this->log("  -> Acquiring delete lock...");
			$got_delete = $this->lock->acquireDelete($bucket, self::DELETE_LOCK_TIMEOUT_MS);
			if (!$got_delete) {
				$this->log("  -> FAILED to acquire Delete Lock for purge - proceeding anyway");
				error_log("CACHE WARNING: Could not acquire delete lock for purge, proceeding anyway: " . $key);
			} else {
				$this->log("  -> Delete lock acquired for purge");
			}
		
			// Mark Invalidation (Update Token)
			// This ensures any waiting sets know they are invalid
			$this->lock->markInvalidation($bucket);
			$this->log("  -> Invalidation marked");
			
				// Acquire Write Lock (Exclusive - Waits for active set ops to finish)
			$this->log("  -> Acquiring write lock...");
			if (!$this->lock->acquireWrite($bucket, self::DELETE_LOCK_TIMEOUT_MS)) {
				$this->log("  -> FAILED to acquire Write Lock for purge, aborting");
				if ($got_delete) $this->lock->releaseDelete();
				return;
			}
			$this->log("  -> Write lock acquired for purge");
			$this->log("  -> Acquiring rebuild lock for purge...");
			$got_rebuild = $this->lock->acquireRebuild($bucket, self::REBUILD_LOCK_TIMEOUT_MS);
			
			if ($got_rebuild) {
				$this->log("  -> Rebuild lock acquired for purge");
			} else {
				$this->log("  -> Rebuild lock not acquired for purge (proceeding anyway)");
			}

				try {
					// PURGE LOGIC: Delete everything (L1 + L2)
			if (!is_dir($dir)) {
				$this->log("  -> Dir not found: " . $dir);
				return;
			}
			$items = @scandir($dir);
			if ($items) {
				foreach ($items as $item) {
					if ($item === '.' || $item === '..' || 
						$item === self::LOCK_FILE_WRITE || 
						$item === self::LOCK_FILE_DELETE || 
						$item === self::LOCK_FILE_REBUILD) {
						continue;
					}
					
					$path = $dir . $item;
					
					if (is_dir($path)) {
						$this->purgeRecursive($path . '/');
					} else {
						@unlink($path);
					}
				}
			}
			$this->log("  -> PURGE complete");
			
			} finally {
				if ($got_rebuild) {
					$this->lock->releaseRebuild();
					$this->log("  -> Rebuild lock released for purge");
				}
				$this->lock->releaseWrite();
				$this->log("  -> Write lock released for purge");
				if ($got_delete) {
					$this->lock->releaseDelete();
					$this->log("  -> Delete lock released for purge");
				}
			}
		} catch (\Throwable $e) {
			$this->log("ERROR PURGE: " . $e->getMessage());
			error_log("CACHE ERROR [purge]: " . $e->getMessage());
		}
	}

	/**
	 * Recursively purge subdirectories (total destruction)
	 */
	private function purgeRecursive(string $dir): void {
		$items = @scandir($dir);
		if (!$items) return;
		
		foreach ($items as $item) {
			if ($item === '.' || $item === '..' || 
				$item === self::LOCK_FILE_WRITE || 
				$item === self::LOCK_FILE_DELETE || 
				$item === self::LOCK_FILE_REBUILD) {
				continue;
			}
			
			$path = $dir . $item;
			
			if (is_dir($path)) {
				$this->purgeRecursive($path . '/');
				@rmdir($path);
			} else {
				@unlink($path);
			}
		}
	}

    /**
     * Destructor - Time-Gated Atomic Garbage Collection
     */
    public function __destruct() {
        try {
            // Skip cleanup if no expiration mode
            if ($this->expire == self::NO_EXPIRATION_THRESHOLD_SECONDS) {
                return;
            }
            
            // 1. Time Window Check (Zero I/O)
            $current_hour = (int)date('G');
            if ($current_hour < $this->gc_start_hour || $current_hour > $this->gc_end_hour) {
                return;
            }
            
            $control_file = $this->cache_dir . self::GC_CONTROL_FILE;
            
            $handle = @fopen($control_file, 'c+');
            if (!$handle) {
                return;
            }

            // 2. Try Acquire Lock (Non-Blocking)
            // If locked, another process is already checking or running GC
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                fclose($handle);
                return;
            }

            try {
                // 3. Read Last Run Timestamp
                $contents = stream_get_contents($handle);
                $last_run = (int)$contents;
                
                $now = time();

                // 4. Check Interval
                if (($now - $last_run) < $this->gc_interval) {
                    return;
                }

                $this->log("GC STARTING");

                // 5. UPDATE TIMESTAMP IMMEDIATELY (Atomic reset)
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, (string)$now);
                fflush($handle);

                // 6. Execute Heavy Cleanup
                $this->cleanupExpired($this->cache_dir);
                
                $this->log("GC COMPLETE");

            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        } catch (\Throwable $e) {
            error_log("CACHE ERROR [gc]: " . $e->getMessage());
        }
    }
    
    /**
     * Recursively cleanup expired cache files with Per-Bucket Locking
     *
     * @param string $dir Root cache directory
     */
    private function cleanupExpired(string $dir): void {
        $items = @scandir($dir);
        if (!$items) {
            return;
        }
        
        // Count Logic: If bucket has too many items, enable aggressive pruning
        $prune_empty = (count($items) > self::DIR_PRUNE_THRESHOLD);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            
            $path = $dir . $item;
            
            // First level directories are Buckets
            if (is_dir($path)) {
                $bucket = $item;
                $this->log("GC processing bucket: " . $bucket);
                
                // Acquire Bucket Locks (Delete Priority)
                if ($this->lock->acquireDelete($bucket, self::DELETE_LOCK_TIMEOUT_MS)) {
                    // Mark token to signal active maintenance
                    $this->lock->markInvalidation($bucket);
                    
                    if ($this->lock->acquireWrite($bucket, self::WRITE_LOCK_TIMEOUT_MS)) {
                        
                        // Acquire Rebuild Lock (Advisory - Forces Gets to L1)
                        $got_rebuild = $this->lock->acquireRebuild($bucket, self::REBUILD_LOCK_TIMEOUT_MS);
                        
                        try {
                            $this->cleanupExpiredRecursive($path . '/', $prune_empty);
                        } finally {
                            if ($got_rebuild) {
                                $this->lock->releaseRebuild();
                            }
                            $this->lock->releaseWrite();
                        }
                    }
                    $this->lock->releaseDelete();
                }
            }
        }
    }

    /**
     * Recursive Cleanup with "Save Zombie" Logic (Promote Expired L2 to L1)
     *
     * @param string $dir Directory to clean
     * @param bool $prune_empty Whether to remove empty directories
     */
    private function cleanupExpiredRecursive(string $dir, bool $prune_empty = false): void {
        $items = @scandir($dir);
        if (!$items) {
             if ($prune_empty) @rmdir($dir);
             return;
        }

        $l2_files = [];
        $l1_files = [];
        $subdirs = [];

        // Single Pass Analysis
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || 
                $item === self::LOCK_FILE_WRITE || 
                $item === self::LOCK_FILE_DELETE || 
                $item === self::LOCK_FILE_REBUILD) {
                continue;
            }
            
            $path = $dir . $item;
            
            if (is_dir($path)) {
                $subdirs[] = $path . '/';
            } elseif (is_numeric($item)) {
                $l2_files[] = $path;
            } elseif (strpos($item, self::L1_PREFIX) === 0) {
                $l1_files[] = $path;
            }
        }

        // Logic: Promote expired L2 to L1 (Zombie), Delete others
        if ($l2_files) {
            rsort($l2_files);
            $newest_l2 = array_shift($l2_files); // Identify candidate
            $l2_time = (int)basename($newest_l2);
            
            if ($l2_time < time()) {
                // CASE: Newest L2 is EXPIRED. Promote to L1.
                $new_l1 = $dir . self::L1_PREFIX . $l2_time;
                if (!@rename($newest_l2, $new_l1)) {
                    @unlink($newest_l2);
                }
                
                foreach ($l2_files as $f) @unlink($f);
                foreach ($l1_files as $f) @unlink($f);
                
            } else {
                // CASE: Newest L2 is VALID. Keep it.
                foreach ($l2_files as $f) @unlink($f);
                
                // Handle L1s: Keep newest L1, delete older ones
                if ($l1_files) {
                    rsort($l1_files);
                    array_shift($l1_files); 
                    foreach ($l1_files as $f) @unlink($f);
                }
            }
        } elseif ($l1_files) {
            // Case: Only L1s exist. Keep newest.
            rsort($l1_files);
            array_shift($l1_files); 
            foreach ($l1_files as $f) @unlink($f);
        }

        // Recurse
        foreach ($subdirs as $subdir) {
            $this->cleanupExpiredRecursive($subdir, $prune_empty);
        }

        if ($prune_empty) {
            @rmdir($dir);
        }
    }
}

/**
 * Class BucketLock
 *
 * Provides per-bucket file-based locking for cache operations.
 * Uses flock() for actual locking.
 *
 * @package Opencart\System\Library\Cache
 */
class BucketLock {
    /**
     * @var string Base directory for cache
     */
    private string $cache_dir;
    
    /**
     * @var string File name of the lock file for write op sync, per bucket
     */
    private string $lock_file_write;

    /**
     * @var string File name of the lock file for delete op priority sync, per bucket
     */
    private string $lock_file_delete;

    /**
     * @var string File name of the lock file for cache value rebuild op sync, per key
     */
    private string $lock_file_rebuild;
    
    /**
     * @var resource|null Current write lock handle
     */
    private $write_handle = null;
    
    /**
     * @var resource|null Current delete lock handle
     */
    private $delete_handle = null;
    
    /**
     * @var resource|null Current rebuild lock handle
     */
    private $rebuild_handle = null;
    
    /**
     * Retry interval in milliseconds
     */
    private const RETRY_MS = 5;

    /**
     * @var bool Debug flag for diagnostic logging
     */
    private const DEBUG_MODE = false;
    
    /**
     * Constructor
     *
     * @param string $cache_dir Base directory for cache
     */
    public function __construct(string $cache_dir, string $lock_file_write, string $lock_file_delete, string $lock_file_rebuild) {
        $this->cache_dir = $cache_dir;
        $this->lock_file_write = $lock_file_write;
        $this->lock_file_delete = $lock_file_delete;
        $this->lock_file_rebuild = $lock_file_rebuild;
    }

    /**
     * DIAGNOSTIC LOGGING
     */
    private function log(string $msg): void {
        if (!self::DEBUG_MODE) {
            return;
        }
        $path = defined('DIR_LOGS') ? DIR_LOGS . 'cache_debug.log' : __DIR__ . '/cache_debug.log';
        @file_put_contents($path, date('H:i:s.') . sprintf('%03d', (int)(microtime(true) * 1000) % 1000) . " [" . getmypid() . "] LOCK | " . $msg . "\n", FILE_APPEND);
    }
    
    /**
     * Get write lock file path for bucket
     */
    private function getWriteLockPath(string $bucket): string {
        return $this->cache_dir . $bucket . '/' . $this->lock_file_write;
    }
    
    /**
     * Get delete lock file path for bucket
     */
    private function getDeleteLockPath(string $bucket): string {
        return $this->cache_dir . $bucket . '/' . $this->lock_file_delete;
    }
    
    /**
     * Get rebuild lock file path for bucket
     */
    private function getRebuildLockPath(string $bucket): string {
        return $this->cache_dir . $bucket . '/' . $this->lock_file_rebuild;
    }
    
    /**
     * Ensure bucket directory exists
     */
    private function ensureBucketExists(string $bucket): bool {
        $dir = $this->cache_dir . $bucket . '/';
        
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
                $this->log("ensureBucketExists FAILED for $bucket");
                return false;
            }
        }
        
        return true;
    }

    /**
     * Get the Invalidation Token (Modification time of the delete lock file)
     * Used to detect if a delete operation occurred between two points in time.
     */
    public function getInvalidationToken(string $bucket): int {
        if (!is_dir($this->cache_dir . $bucket)) return 0;
        
        $path = $this->getDeleteLockPath($bucket);
        clearstatcache(true, $path); // Ensure we get fresh mtime
        
        if (file_exists($path)) {
            return (int)filemtime($path);
        }
        return 0;
    }

    /**
     * Mark Invalidation (Update Token)
     * Updates the mtime of the delete lock file.
     */
    public function markInvalidation(string $bucket): void {
        if (!$this->ensureBucketExists($bucket)) return;
        $path = $this->getDeleteLockPath($bucket);
        @touch($path);
        clearstatcache(true, $path);
    }
    
    /**
     * Check if a delete lock is currently held (Non-Blocking)
     * Robust implementation using local error suppression.
     *
     * @param string $bucket
     * @return bool True if NO delete is active (safe to write), False if delete is active
     */
    public function checkDelete(string $bucket): bool {
        // If bucket dir doesn't exist, no delete can be active
        if (!is_dir($this->cache_dir . $bucket)) {
            return true;
        }

        $path = $this->getDeleteLockPath($bucket);
        
        // 1. First Line of Defense: is_file()
        // We keep this because it is MUCH faster than triggering an error handler 
        // for the common case (when the lock file genuinely doesn't exist).
        if (!is_file($path)) {
            return true; 
        }
        
        $handle = false;

        // 2. The "Silence Scope"
        // We temporarily install a dummy error handler that just returns true.
        // This swallows the E_WARNING if the file was deleted in the microsecond 
        // after the is_file() check above (The Chaos Race Condition).
        set_error_handler(function() { return true; });
        
        try {
            // We use 'r' to ensure we never create the file or modify mtime
            $handle = fopen($path, 'r');
        } catch (\Throwable $e) {
            // Catch any unexpected fatal errors
        } finally {
            // Always restore the original handler immediately
            restore_error_handler();
        }
        
        // If fopen failed (race condition), handle is false.
        // We treat this as "No Lock Held" (Safe to write).
        if (!$handle) {
            return true; 
        }

        // 3. Try to acquire Shared Lock (Reader). 
        // If a Delete (Writer/Exclusive) holds the lock, this fails immediately (LOCK_NB).
        $can_lock = flock($handle, LOCK_SH | LOCK_NB);
        
        if ($can_lock) {
            flock($handle, LOCK_UN);
        }
        
        fclose($handle);
        return $can_lock;
    }

    /**
     * Acquire Delete lock (Exclusive)
     *
     * @param string $bucket
     * @param int $timeout_ms
     * @return bool
     */
    public function acquireDelete(string $bucket, int $timeout_ms): bool {
        if (!$this->ensureBucketExists($bucket)) {
            return false;
        }

        $path = $this->getDeleteLockPath($bucket);
        $start = microtime(true) * 1000;
        $attempt = 0;

        while ((microtime(true) * 1000) - $start < $timeout_ms) {
            $attempt++;
            // Use 'c' here because we MUST create the file to lock it
            $handle = @fopen($path, 'c');
            if (!$handle) {
                $this->log("acquireDelete [$bucket] attempt $attempt: fopen failed");
                usleep(self::RETRY_MS * 1000);
                continue;
            }
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                $this->delete_handle = $handle;
                $this->log("acquireDelete [$bucket] SUCCESS after $attempt attempts");
                return true;
            }
            fclose($handle);
            usleep(self::RETRY_MS * 1000);
        }
        $this->log("acquireDelete [$bucket] TIMEOUT after $attempt attempts (" . round(microtime(true) * 1000 - $start) . "ms)");
        return false;
    }

    /**
     * Release delete lock
     */
    public function releaseDelete(): void {
        if ($this->delete_handle) {
            flock($this->delete_handle, LOCK_UN);
            fclose($this->delete_handle);
            $this->delete_handle = null;
            $this->log("releaseDelete");
        }
    }

    /**
     * Acquire write lock for bucket
     *
     * @param string $bucket Bucket name
     * @param int $timeout_ms Timeout in milliseconds
     * @return bool True if acquired
     */
    public function acquireWrite(string $bucket, int $timeout_ms): bool {
        if (!$this->ensureBucketExists($bucket)) {
            return false;
        }
        
        $path = $this->getWriteLockPath($bucket);
        $start = microtime(true) * 1000;
        $attempt = 0;
        
        while ((microtime(true) * 1000) - $start < $timeout_ms) {
            $attempt++;
            $handle = @fopen($path, 'c');
            if (!$handle) {
                $this->log("acquireWrite [$bucket] attempt $attempt: fopen failed");
                usleep(self::RETRY_MS * 1000);
                continue;
            }
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                $this->write_handle = $handle;
                $this->log("acquireWrite [$bucket] SUCCESS after $attempt attempts");
                return true;
            }
            fclose($handle);
            usleep(self::RETRY_MS * 1000);
        }
        $this->log("acquireWrite [$bucket] TIMEOUT after $attempt attempts (" . round(microtime(true) * 1000 - $start) . "ms)");
        return false;
    }
    
    /**
     * Release write lock
     */
    public function releaseWrite(): void {
        if ($this->write_handle) {
            flock($this->write_handle, LOCK_UN);
            fclose($this->write_handle);
            $this->write_handle = null;
            $this->log("releaseWrite");
        }
    }
    
    /**
     * Acquire rebuild lock for bucket
     *
     * @param string $bucket Bucket name
     * @param int $timeout_ms Timeout in milliseconds
     * @return bool True if acquired
     */
    public function acquireRebuild(string $bucket, int $timeout_ms): bool {
        if (!$this->ensureBucketExists($bucket)) {
            return false;
        }
        
        $path = $this->getRebuildLockPath($bucket);
        $start = microtime(true) * 1000;
        $attempt = 0;
        
        while ((microtime(true) * 1000) - $start < $timeout_ms) {
            $attempt++;
            $handle = @fopen($path, 'c');
            if (!$handle) {
                $this->log("acquireRebuild [$bucket] attempt $attempt: fopen failed");
                usleep(self::RETRY_MS * 1000);
                continue;
            }
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                $this->rebuild_handle = $handle;
                $this->log("acquireRebuild [$bucket] SUCCESS after $attempt attempts");
                return true;
            }
            fclose($handle);
            usleep(self::RETRY_MS * 1000);
        }
        $this->log("acquireRebuild [$bucket] TIMEOUT after $attempt attempts (" . round(microtime(true) * 1000 - $start) . "ms)");
        return false;
    }
    
    /**
     * Release rebuild lock
     */
    public function releaseRebuild(): void {
        if ($this->rebuild_handle) {
            flock($this->rebuild_handle, LOCK_UN);
            fclose($this->rebuild_handle);
            $this->rebuild_handle = null;
            $this->log("releaseRebuild");
        }
    }
}
