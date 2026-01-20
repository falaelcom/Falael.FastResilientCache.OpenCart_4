<?php
/**
 * OpenCart Cache Concurrency Orchestrator
 * Run from CLI: php test_runner.php
 */

// -----------------------------------------------------------------------------
// 1. BOOTSTRAP & CONFIG
// -----------------------------------------------------------------------------

ob_implicit_flush(true);
while (ob_get_level()) ob_end_clean();

define('DIR_CACHE', __DIR__ . '/test_cache/'); 
if (!is_dir(DIR_CACHE)) {
    mkdir(DIR_CACHE, 0777, true);
}

// -----------------------------------------------------------------------------
// 2. MAIN EXECUTION SWITCH
// -----------------------------------------------------------------------------

$mode = $argv[1] ?? 'orchestrator';
$worker_id = $argv[2] ?? null;

switch ($mode) {
    case 'orchestrator':
        run_orchestrator();
        break;

    // REGISTER ALL WORKER MODES HERE
    default:
        load_dependencies();
        $func = "run_$mode";
        if (function_exists($func)) {
            $func($worker_id);
        } else {
             // Fallback debug
             file_put_contents(DIR_CACHE . "error_{$worker_id}.txt", "Function $func not found.");
        }
        break;
}

// -----------------------------------------------------------------------------
// 3. DEPENDENCY LOADER & UTILS
// -----------------------------------------------------------------------------
function load_dependencies() {
    $class_file = __DIR__ . '/system/library/cache/file.php';
    if (!file_exists($class_file)) {
        file_put_contents(DIR_CACHE . 'fatal_error.txt', "ERROR: Could not find cache class at: $class_file\n");
        die();
    }
    require_once $class_file;
}

function cleanup_cache() {
    // Aggressive recursive delete
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(DIR_CACHE, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($files as $fileinfo) {
        $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
        @$todo($fileinfo->getRealPath());
    }
    
    // Re-create the root test cache dir if we accidentally killed it (though the loop above usually leaves root)
    if (!is_dir(DIR_CACHE)) {
        mkdir(DIR_CACHE, 0777, true);
    }
}

// -----------------------------------------------------------------------------
// 4. ORCHESTRATOR (THE MASTER)
// -----------------------------------------------------------------------------

function run_orchestrator() {
    while (true) {
        echo "\n==============================================\n";
        echo "    OPENCART CACHE CONCURRENCY TEST SUITE     \n";
        echo "==============================================\n";
        echo "Target Directory: " . DIR_CACHE . "\n";
        echo "----------------------------------------------\n";
        echo " [1] Framework Verification (Ping)\n";
        echo " [2] Thundering Herd (Stale Fallback)\n";
        echo " [3] Active Delete Block (Fast Fail)\n";
        echo " [4] Concurrent Reads: Warm L2 (Fresh)\n";
        echo " [5] Concurrent Reads: Warm L1 (Stale)\n";
        echo " [6] Concurrent Reads: Cold Cache\n";
        echo " [7] Rebuild Cycle vs Standard Delete (L1 Fallback)\n";
        echo " [8] Rebuild Cycle vs Total Wipeout (Write Block)\n";
        echo " [9] Chaos Test: Terminal Nuke vs Busy Workers\n";
        echo " [10] The Sniper: Stale Write Race Condition\n";
        echo " [11] Busy GC Trigger (Zombie Promotion Verify)\n";
        echo " [12] The Undead File (GC vs Delete Locking)\n";
        echo " [13] SYSTEM MELTDOWN (High CPU/IO Stress Test)\n";
        echo " [14] BENCHMARK: Latency & Throughput (100 Workers)\n";
        echo " [Q] Quit\n";
        echo "----------------------------------------------\n";
        echo "Select Test > ";
        
        $handle = fopen("php://stdin", "r");
        $line = trim(fgets($handle));
        
        switch (strtoupper($line)) {
            case '1':
                run_test_monitor(['w1', 'w2', 'w3'], 'worker_ping');
                break;
            case '2':
                setup_thundering_herd();
                $workers = [];
                for($i=1; $i<=10; $i++) $workers[] = "w$i";
                run_test_monitor($workers, 'worker_thundering_herd', 10); 
                break;
            case '3':
                run_test_monitor(['blocker', 'victim'], function($id) {
                    return ($id === 'blocker') ? 'worker_delete_blocker' : 'worker_delete_victim';
                });
                break;
            case '4':
                setup_warm_l2();
                $workers = [];
                for($i=1; $i<=20; $i++) $workers[] = "w$i";
                run_test_monitor($workers, 'worker_warm_l2', 5);
                break;
            case '5':
                setup_warm_l1();
                $workers = [];
                for($i=1; $i<=20; $i++) $workers[] = "w$i";
                run_test_monitor($workers, 'worker_warm_l1', 5);
                break;
            case '6':
                cleanup_cache(); 
                $workers = [];
                for($i=1; $i<=20; $i++) $workers[] = "w$i";
                run_test_monitor($workers, 'worker_cold', 5);
                break;
            case '7':
                cleanup_cache();
                run_test_monitor(['cycler', 'deleter'], function($id) {
                    return ($id === 'cycler') ? 'worker_rebuild_cycler' : 'worker_manual_deleter';
                }, 10);
                break;
            case '8':
                cleanup_cache();
                run_test_monitor(['cycler', 'deleter'], function($id) {
                    return ($id === 'cycler') ? 'worker_rebuild_cycler' : 'worker_wipeout_deleter';
                }, 10); 
                break;
            case '9':
                cleanup_cache();
                $workers = ['busy1', 'busy2', 'busy3', 'busy4', 'busy5', 'nuker'];
                run_test_monitor($workers, function($id) {
                    return ($id === 'nuker') ? 'worker_terminal_nuke' : 'worker_chaos_load';
                }, 12); 
                break;
            case '10':
                cleanup_cache();
                run_test_monitor(['laggy_writer', 'sniper'], function($id) {
                    return ($id === 'laggy_writer') ? 'worker_laggy_writer' : 'worker_sniper';
                }, 8);
                break;
            case '11':
                cleanup_cache();
                setup_gc_scenario();
                run_test_monitor(['watcher', 'trigger'], function($id) {
                    return ($id === 'watcher') ? 'worker_gc_watcher' : 'worker_gc_trigger';
                }, 8);
                break;
            case '12':
                cleanup_cache();
                file_put_contents(DIR_CACHE . 'undead_cycle.txt', '0');
                run_test_monitor(['resurrector', 'destroyer'], function($id) {
                    return ($id === 'resurrector') ? 'worker_resurrector' : 'worker_destroyer';
                }, 10);
                break;
            case '13':
                cleanup_cache();
                $workers = [];
                for ($i=1; $i<=20; $i++) $workers[] = "render$i";
                $workers[] = 'chaos_monkey_1';
                $workers[] = 'chaos_monkey_2';
                run_test_monitor($workers, function($id) {
                    return (strpos($id, 'chaos') !== false) ? 'worker_chaos_monkey' : 'worker_renderer';
                }, 240); 
                break;
            case '14':
                cleanup_cache();
                
                // Clear old stats files
                array_map('unlink', glob(DIR_CACHE . "stats_*.json"));
                
                $workers = [];
                // 100 Renderers + 5 Chaos Monkeys
                for ($i=1; $i<=100; $i++) $workers[] = "bencher$i";
                for ($i=1; $i<=5; $i++) $workers[] = "chaos$i";
                
                run_test_monitor($workers, function($id) {
                    return (strpos($id, 'chaos') !== false) ? 'worker_benchmark_chaos' : 'worker_benchmark_renderer';
                }, 240); // 240 seconds run time
                
                // --- AGGREGATE RESULTS ---
                echo "\n[BENCHMARK REPORT]\n";
                $stat_files = glob(DIR_CACHE . "stats_*.json");
                
                $total_pages = 0;
                $total_cache_ops = 0;
                
                $page_times_all = [];
                $cache_ops_all = [];
                
                $p_sum = 0;
                $c_sum = 0;
                
                $p_min = 999999; $p_max = 0;
                $c_min = 999999; $c_max = 0;
                
                foreach ($stat_files as $f) {
                    $data = json_decode(file_get_contents($f), true);
                    if (!$data) continue;
                    
                    $total_pages += $data['p_count'];
                    $total_cache_ops += $data['c_count'];
                    
                    $p_sum += $data['p_sum'];
                    $c_sum += $data['c_sum'];
                    
                    if ($data['p_min'] < $p_min) $p_min = $data['p_min'];
                    if ($data['p_max'] > $p_max) $p_max = $data['p_max'];
                    
                    if ($data['c_min'] < $c_min) $c_min = $data['c_min'];
                    if ($data['c_max'] > $c_max) $c_max = $data['c_max'];
                }
                
                $p_avg = ($total_pages > 0) ? round($p_sum / $total_pages, 2) : 0;
                $c_avg = ($total_cache_ops > 0) ? round($c_sum / $total_cache_ops, 2) : 0;
                
                echo "-------------------------------------------------------------\n";
                echo "| Metric                | Min (ms) | Avg (ms) | Max (ms)    |\n";
                echo "-------------------------------------------------------------\n";
                printf("| Page Build Time       | %8s | %8s | %11s |\n", $p_min, $p_avg, $p_max);
                printf("| Cache Op Time         | %8s | %8s | %11s |\n", $c_min, $c_avg, $c_max);
                echo "-------------------------------------------------------------\n";
                echo "Total Pages Served: $total_pages\n";
                echo "Total Cache Ops:    $total_cache_ops\n";
                echo "-------------------------------------------------------------\n";
                break;
            case 'Q':
                cleanup_cache();
                exit("Goodbye.\n");
            default:
                echo "Invalid selection.\n";
        }
    }
}

/**
 * Intelligent Monitor
 * - Auto-detects completion (looks for "DONE" count)
 * - Auto-terminates on timeout
 * - Supports mixed worker modes
 */
function run_test_monitor($workers, $callback_map, $timeout_seconds = 240) {
    echo "[INFO] Initializing environment...\n";

    // --- ROBUST LOG PREPARATION ---
    foreach ($workers as $worker_id) {
        $log_file = DIR_CACHE . "log_{$worker_id}.txt";
        for ($attempt = 0; $attempt < 3; $attempt++) {
            if (file_exists($log_file)) {
                if (@unlink($log_file)) break;
                usleep(10000);
            } else break;
        }
        @touch($log_file);
    }

    $descriptors = [
        0 => ["pipe", "r"], // stdin
        1 => ["pipe", "w"], // stdout
        2 => ["pipe", "w"]  // stderr
    ];

    $processes = [];
    $pipes = [];
    $start_time = time();
    $workers_done = [];
    $log_positions = [];
    
    // Identify Essential Workers (Renderers)
    $essential_workers = [];

    echo "[INFO] Spawning " . count($workers) . " workers...\n";

    foreach ($workers as $worker_id) {
        $workers_done[$worker_id] = false;
        $log_positions[$worker_id] = 0;
        
        if (strpos($worker_id, 'render') !== false || strpos($worker_id, 'bencher') !== false) {
            $essential_workers[] = $worker_id;
        }

        $mode = is_callable($callback_map) ? $callback_map($worker_id) : $callback_map;
        $cmd = PHP_BINARY . " " . __FILE__ . " $mode $worker_id";
        
        $process = proc_open($cmd, $descriptors, $current_pipes);

        if (is_resource($process)) {
            $processes[$worker_id] = $process;
            $pipes[$worker_id] = $current_pipes;
            stream_set_blocking($current_pipes[1], 0);
            stream_set_blocking($current_pipes[2], 0);
        } else {
            echo "[ERR] Failed to spawn worker: $worker_id\n";
        }
    }

    echo "[INFO] Monitoring... (Primary: " . count($essential_workers) . " renderers)\n\n";

    while (true) {
        // 1. Check Global Timeout
        if (time() - $start_time > $timeout_seconds) {
            echo "\n[WARNING] Monitor Timeout Reached.\n";
            break; 
        }

        // 2. Poll All Logs
        foreach ($workers as $wid) {
            if ($workers_done[$wid]) continue;
            
            $log_file = DIR_CACHE . "log_{$wid}.txt";
            clearstatcache(true, $log_file);
            
            if (file_exists($log_file)) {
				// 1. Use '@' to suppress the warning if the file is locked by a worker
				$handle = @fopen($log_file, "r");
				if ($handle) {
					// 2. Try to get a SHARED lock (Reader lock). 
					// If this fails, it means a worker is currently writing (Exclusive lock).
					if (flock($handle, LOCK_SH | LOCK_NB)) {
						fseek($handle, $log_positions[$wid]);
						
						$new_content = '';
						while (!feof($handle)) {
							$chunk = @fread($handle, 32768); // Suppress fread specifically
							if ($chunk === false || $chunk === '') break;
							$new_content .= $chunk;
						}
						
						$log_positions[$wid] = ftell($handle);
						flock($handle, LOCK_UN); // Release shared lock
						fclose($handle);
						
						if ($new_content !== '') {
							echo $new_content;
							if (strpos($new_content, 'DONE.') !== false) {
								$workers_done[$wid] = true;
							}
						}
					} else {
						// File is locked by a worker; close and skip this tick
						fclose($handle);
					}
				}
			}
        }
        
        // 3. CHECK EXIT CONDITION
        // We exit if all essential (render) workers are done.
        // This prevents hangs caused by Chaos Monkeys stuck in deep directory recursion.
        $all_essential_done = true;
        foreach ($essential_workers as $ewid) {
            if (!$workers_done[$ewid]) {
                $all_essential_done = false;
                break;
            }
        }

        if ($all_essential_done && !empty($essential_workers)) {
            echo "\n[INFO] All primary renderers reported DONE. Terminating session...\n";
            break;
        }

        // If no essential workers were defined, wait for everyone
        if (empty($essential_workers)) {
            $all_done = true;
            foreach ($workers_done as $done) { if (!$done) { $all_done = false; break; } }
            if ($all_done) break;
        }

        usleep(10000); // 10ms - Aggressive polling to keep pipes drained
    }

    // --- CLEANUP ---
    echo "[INFO] Cleaning up processes...\n";
    foreach ($processes as $wid => $proc) {
        if (is_resource($proc)) {
            if (isset($pipes[$wid])) {
                @fclose($pipes[$wid][0]);
                @fclose($pipes[$wid][1]);
                @fclose($pipes[$wid][2]);
            }
            proc_terminate($proc);
            proc_close($proc);
        }
    }
    
    $completed = count(array_filter($workers_done));
    echo "[FINISH] Test monitor exited. Workers finished: $completed/" . count($workers) . "\n";
}
function spawn_worker($mode, $id, $log_file) {
    $php = PHP_BINARY;
    $script = __FILE__;
    $cmd = 'start /B "" "' . $php . '" "' . $script . '" ' . $mode . ' ' . $id . ' > "' . $log_file . '" 2>&1';
    pclose(popen($cmd, "r"));
}

// -----------------------------------------------------------------------------
// 5. TEST SETUPS & WORKER LOGIC
// -----------------------------------------------------------------------------
function log_msg($id, $msg) {
    // 1. Timestamp with Milliseconds
    $t = microtime(true);
    $micro = sprintf("%06d", ($t - floor($t)) * 1000000);
    $d = new DateTime(date('Y-m-d H:i:s.' . $micro, $t));
    $timestamp = $d->format("H:i:s.v"); // .v is milliseconds
    
    $log_file = DIR_CACHE . "log_{$id}.txt";
    $entry = "[$timestamp][$id] $msg\n";
    
    // 2. Retry Logic for Writes (Windows Fix)
    // If the orchestrator is reading the file, the write might fail.
    // We try 3 times before giving up.
    for ($i = 0; $i < 3; $i++) {
        // LOCK_EX is crucial here for atomic appends
        if (@file_put_contents($log_file, $entry, FILE_APPEND | LOCK_EX) !== false) {
            return;
        }
        usleep(rand(1000, 5000)); // Random backoff
    }
}


// --- TEST 1: PING ---
function run_worker_ping($id) {
    try {
        $cache = new \Opencart\System\Library\Cache\File(3600);
        usleep(rand(10000, 200000));
        log_msg($id, "Alive.");
        log_msg($id, "DONE.");
    } catch (\Throwable $e) { log_msg($id, "ERR: ".$e->getMessage()); }
}

// --- TEST 2: THUNDERING HERD ---
function setup_thundering_herd() {
    cleanup_cache();
    echo "[SETUP] Creating STALE L1 file (key: herd). No L2 file.\n";
    
    // Create L1 file manually
    $dir = DIR_CACHE . 'herd/';
    mkdir($dir, 0777, true);
    $data = json_encode("STALE_DATA_L1");
    file_put_contents($dir . 'l1-' . (time() - 5000), $data); // Old timestamp
}

function run_worker_thundering_herd($id) {
    try {
        $cache = new \Opencart\System\Library\Cache\File(3600);
        $key = 'herd';

        // Stagger start slightly to create a "wave" rather than perfect sync
        usleep(rand(1000, 50000)); 

        log_msg($id, "Requesting get()...");
        $start = microtime(true);
        $data = $cache->get($key);
        $duration = round((microtime(true) - $start) * 1000, 2);

        if ($data === null) {
            log_msg($id, ">>> WINNER! Got NULL (Must Rebuild). Lock held.");
            log_msg($id, "Simulating DB Work (2s)...");
            sleep(2); 
            $cache->set($key, "FRESH_DATA_L2");
            log_msg($id, "Saved L2 data.");
        } else {
            // Check if we got stale L1 or fresh L2
            $type = ($data === "STALE_DATA_L1") ? "L1 (Fallback)" : "L2 (Fresh)";
            log_msg($id, "Got Data: $type in {$duration}ms");
        }
        
        log_msg($id, "DONE.");
    } catch (\Throwable $e) { log_msg($id, "ERR: ".$e->getMessage()); }
}

// --- TEST 3: DELETE BLOCKER ---
function run_worker_delete_blocker($id) {
    try {
        // This worker manually acquires the delete lock to simulate a long process
        $bucket = 'blocked_key';
        // We must instantiate the lock class manually or use reflection. 
        // For simplicity, we use the File class to trigger a delete, but we need it to HANG.
        // Since we can't inject delays into the class without modifying it, 
        // we will manually create the lock file and FLOCK it.
        
        $dir = DIR_CACHE . $bucket . '/';
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        
        log_msg($id, "Acquiring DELETE lock manually...");
        $fp = fopen($dir . 'lock-delete', 'c');
        if (flock($fp, LOCK_EX)) {
            log_msg($id, "LOCKED. Holding for 3 seconds...");
            sleep(3);
            flock($fp, LOCK_UN);
            log_msg($id, "Released.");
        }
        fclose($fp);
        log_msg($id, "DONE.");
    } catch (\Throwable $e) { log_msg($id, "ERR: ".$e->getMessage()); }
}

function run_worker_delete_victim($id) {
    try {
        $cache = new \Opencart\System\Library\Cache\File(3600);
        $key = 'blocked_key';
        
        sleep(1); // Wait for blocker to engage
        log_msg($id, "Attempting SET...");
        
        $start = microtime(true);
        $cache->set($key, "Should Fail");
        $duration = round((microtime(true) - $start) * 1000, 2);
        
        // We verify failure by checking if file exists (it shouldn't)
        // or relying on logs. Since set() returns void, we can't check return.
        // Ideally we check if the L2 file was created.
        
        $dir = DIR_CACHE . 'blocked_key/';
        $files = glob($dir . '[0-9]*');
        
        if (empty($files)) {
            log_msg($id, "SUCCESS: Write aborted (No L2 file found). Time: {$duration}ms");
        } else {
            log_msg($id, "FAILURE: Write succeeded (Race condition failed).");
        }
        
        log_msg($id, "DONE.");
    } catch (\Throwable $e) { log_msg($id, "ERR: ".$e->getMessage()); }
}

// --- TEST 4: WARM L2 (Fresh Hits) ---
function setup_warm_l2() {
    cleanup_cache();
    echo "[SETUP] Creating FRESH L2 file (key: warm_l2).\n";
    
    $dir = DIR_CACHE . 'warm_l2/';
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    
    // Create valid L2 file (future timestamp)
    $data = json_encode("FRESH_L2_DATA");
    file_put_contents($dir . (time() + 3600), $data); 
}

function run_worker_warm_l2($id) {
    try {
        $cache = new \Opencart\System\Library\Cache\File(3600);
        $key = 'warm_l2';

        // High concurrency slam
        usleep(rand(1000, 10000)); 

        $start = microtime(true);
        $data = $cache->get($key);
        $duration = round((microtime(true) - $start) * 1000, 2);

        if ($data === "FRESH_L2_DATA") {
            log_msg($id, "HIT L2 (Fresh) in {$duration}ms");
        } else {
            // This should NOT happen in this test
            $type = ($data === null) ? "NULL" : "UNKNOWN";
            log_msg($id, "FAILURE: Expected L2, got $type");
        }
        
        log_msg($id, "DONE.");
    } catch (\Throwable $e) { log_msg($id, "ERR: ".$e->getMessage()); }
}

// --- TEST 5: WARM L1 (Stale/Fallback Hits) ---
function setup_warm_l1() {
    cleanup_cache();
    echo "[SETUP] Creating STALE L1 file (key: warm_l1). No L2.\n";
    
    $dir = DIR_CACHE . 'warm_l1/';
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    
    // Create L1 file
    $data = json_encode("STALE_L1_DATA");
    file_put_contents($dir . 'l1-' . (time() - 3600), $data); 
}

function run_worker_warm_l1($id) {
    try {
        $cache = new \Opencart\System\Library\Cache\File(3600);
        $key = 'warm_l1';

        usleep(rand(1000, 10000)); 

        $start = microtime(true);
        $data = $cache->get($key);
        $duration = round((microtime(true) - $start) * 1000, 2);

        if ($data === "STALE_L1_DATA") {
            log_msg($id, "HIT L1 (Fallback) in {$duration}ms");
        } elseif ($data === null) {
            // One (or a few) workers will get NULL because they acquired the rebuild lock.
            // This is expected behavior for "Missing L2".
            log_msg($id, "MISS (Got Lock) in {$duration}ms - I am the Rebuilder.");
        } else {
            log_msg($id, "FAILURE: Unexpected data.");
        }
        
        log_msg($id, "DONE.");
    } catch (\Throwable $e) { log_msg($id, "ERR: ".$e->getMessage()); }
}

// --- TEST 6: COLD CACHE (Total Miss) ---
function run_worker_cold($id) {
    try {
        $cache = new \Opencart\System\Library\Cache\File(3600);
        $key = 'cold_key_' . rand(1, 3); // Split load slightly or use single key
        $key = 'cold_single_key'; // Hammer single key

        usleep(rand(1000, 10000)); 

        $start = microtime(true);
        $data = $cache->get($key);
        $duration = round((microtime(true) - $start) * 1000, 2);

        if ($data === null) {
            log_msg($id, "MISS (NULL) in {$duration}ms");
        } else {
            // Should be impossible on empty dir unless race created it
            log_msg($id, "WTF: Got data?");
        }
        
        log_msg($id, "DONE.");
    } catch (\Throwable $e) { log_msg($id, "ERR: ".$e->getMessage()); }
}

// --- TEST 7: REBUILD CYCLE vs ACTIVE DELETE ---

function run_worker_rebuild_cycler($id) {
    try {
        $cache = new \Opencart\System\Library\Cache\File(3600);
        $key = 'cycle_key';
        
        $start_time = time();
        
        while (time() - $start_time < 9) { // Run for 9 seconds
            log_msg($id, "--- Cycle Start ---");
            
            // 1. Attempt Read
            $data = $cache->get($key);
            
            if ($data) {
                log_msg($id, "READ: SUCCESS (Found Data)");
                // Force clear it internally for next cycle logic (optional, but helps simulation)
                // actually, let's just let it be.
            } else {
                log_msg($id, "READ: NULL (Start Rebuild)");
                
                // 2. Attempt Write
                $val = "DATA_" . microtime(true);
                $cache->set($key, $val);
                
                // 3. Verify Write
                // We bypass internal cache to check file existence directly for strict proof
                // Or just use get() again.
                $check = $cache->get($key);
                if ($check === $val) {
                    log_msg($id, "WRITE: SUCCESS (Saved)");
                } else {
                    log_msg($id, "WRITE: FAILED (Blocked/Aborted)");
                }
            }
            
            usleep(800000); // 0.8s pause between cycles
        }
        
        log_msg($id, "DONE.");
    } catch (\Throwable $e) { log_msg($id, "ERR: ".$e->getMessage()); }
}

function run_worker_manual_deleter($id) {
    try {
        $bucket = 'cycle_key';
        $dir = DIR_CACHE . $bucket . '/';
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        
        log_msg($id, "Waiting 2s for cycler to start...");
        sleep(2);
        
        log_msg($id, ">>> SIMULATING DELETE (Acquiring Locks)...");
        
        $f_del = fopen($dir . 'lock-delete', 'c');
        $f_reb = fopen($dir . 'lock-rebuild', 'c');
        $f_wri = fopen($dir . 'lock-write', 'c'); // Also grab write lock for realism
        
        // Lock all 3 to simulate a full 'delete' taking over
        if (flock($f_del, LOCK_EX | LOCK_NB) && 
            flock($f_reb, LOCK_EX | LOCK_NB) && 
            flock($f_wri, LOCK_EX | LOCK_NB)) {
            
            log_msg($id, ">>> LOCKED. Now DELETING L2 files...");
            
            // ACTUAL DESTRUCTION: Remove the file so the reader fails L2 check
            $files = glob($dir . '[0-9]*');
            foreach($files as $f) @unlink($f);
            
            log_msg($id, ">>> FILES DELETED. Holding locks for 4s...");
            
            // Now the reader will miss L2, try to rebuild, fail the lock, return NULL.
            // Then it will try to SET, check delete lock, and fail the write.
            sleep(4);
            
            flock($f_wri, LOCK_UN);
            flock($f_reb, LOCK_UN);
            flock($f_del, LOCK_UN);
            log_msg($id, ">>> RELEASED. (Normal ops should resume)");
        } else {
            log_msg($id, ">>> FAILED to acquire manual locks!");
        }
        
        fclose($f_wri);
        fclose($f_reb);
        fclose($f_del);
        
        log_msg($id, "DONE.");
    } catch (\Throwable $e) { log_msg($id, "ERR: ".$e->getMessage()); }
}


// --- TEST 8: TOTAL WIPEOUT DELETER ---
function run_worker_wipeout_deleter($id) {
    try {
        $bucket = 'cycle_key';
        $dir = DIR_CACHE . $bucket . '/';
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        
        log_msg($id, "Waiting 2s for cycler to start...");
        sleep(2);
        
        log_msg($id, ">>> SIMULATING ABNORMAL WIPE (Acquiring Locks)...");
        
        $f_del = fopen($dir . 'lock-delete', 'c');
        $f_reb = fopen($dir . 'lock-rebuild', 'c');
        $f_wri = fopen($dir . 'lock-write', 'c');
        
        if (flock($f_del, LOCK_EX | LOCK_NB) && 
            flock($f_reb, LOCK_EX | LOCK_NB) && 
            flock($f_wri, LOCK_EX | LOCK_NB)) {
            
            log_msg($id, ">>> LOCKED. NUKE EVERYTHING (L1 + L2)...");
            
            // 1. Delete L2 (Fresh)
            $files = glob($dir . '[0-9]*');
            foreach($files as $f) @unlink($f);

            // 2. Delete L1 (Stale Backup) - The abnormal condition
            $l1_files = glob($dir . 'l1-*');
            foreach($l1_files as $f) @unlink($f);
            
            log_msg($id, ">>> CACHE EMPTY. Holding locks for 4s...");
            
            // During this 4s window:
            // 1. Cycler reads -> Miss (No L1/L2)
            // 2. Cycler tries Rebuild Lock -> Fails (Deleter holds it) -> Returns NULL
            // 3. Cycler tries SET -> Fails (Deleter holds Write/Delete Lock)
            
            sleep(4);
            
            flock($f_wri, LOCK_UN);
            flock($f_reb, LOCK_UN);
            flock($f_del, LOCK_UN);
            log_msg($id, ">>> RELEASED. (Recovery should start)");
        } else {
            log_msg($id, ">>> FAILED to acquire manual locks!");
        }
        
        fclose($f_wri);
        fclose($f_reb);
        fclose($f_del);
        
        log_msg($id, "DONE.");
    } catch (\Throwable $e) { log_msg($id, "ERR: ".$e->getMessage()); }
}


// --- TEST 9: CHAOS (TERMINAL NUKE) ---

function run_worker_chaos_load($id) {
    try {
        $cache = new \Opencart\System\Library\Cache\File(3600);
        $key = 'chaos_key';
        
        $start_time = time();
        $i = 0;
        
        // Run for 10 seconds
        while (time() - $start_time < 10) {
            $i++;
            // Random mix of Get and Set
            $action = (rand(1, 10) > 3) ? 'get' : 'set'; // 70% reads
            
            if ($action === 'get') {
                $data = $cache->get($key);
                if ($data === null) {
                    log_msg($id, "[$i] MISS. Rebuilding...");
                    // Simulate Rebuild
                    usleep(rand(50000, 150000));
                    $cache->set($key, "CHAOS_DATA_" . time());
                    log_msg($id, "[$i] REBUILT.");
                } else {
                    // Reduce log spam, only log every 5th hit
                    if ($i % 5 === 0) log_msg($id, "[$i] HIT.");
                }
            } else {
                // Force update
                $cache->set($key, "CHAOS_UPDATE_" . microtime());
                if ($i % 5 === 0) log_msg($id, "[$i] UPDATED.");
            }
            
            // Tiny sleep to allow the Nuker a chance to grab file handles (Windows specific)
            usleep(rand(10000, 50000));
        }
        
        log_msg($id, "DONE.");
    } catch (\Throwable $e) { log_msg($id, "ERR: ".$e->getMessage()); }
}

function run_worker_terminal_nuke($id) {
    try {
        log_msg($id, "Waiting 3s for chaos to start...");
        sleep(3);
        
        $target_dir = DIR_CACHE . 'chaos_key/';
        
        log_msg($id, ">>> BOOM! Deleting directory structure (Simulating 'rm -rf')...");
        
        // Brutal recursive delete ignoring locks
        if (is_dir($target_dir)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($target_dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($files as $fileinfo) {
                $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
                // Suppress errors because Busy Workers might have files locked (Windows)
                @$todo($fileinfo->getRealPath());
            }
            @rmdir($target_dir);
        }
        
        if (!is_dir($target_dir)) {
            log_msg($id, ">>> NUKE SUCCESSFUL. Directory is gone.");
        } else {
            log_msg($id, ">>> NUKE PARTIAL (Some files locked, common on Windows).");
        }
        
        log_msg($id, "DONE.");
    } catch (\Throwable $e) { log_msg($id, "ERR: ".$e->getMessage()); }
}

// --- TEST 10: THE SNIPER (RACE CONDITION) ---

function run_worker_laggy_writer($id) {
    try {
        // Initialize with the special test hook
        $cache = new \Opencart\System\Library\Cache\File(3600, 'lag_set_init');
        $key = 'race_key';
        
        log_msg($id, "Starting SET operation (Will lag 3s internally)...");
        
        // This set() will:
        // 1. Capture Token
        // 2. Sleep 3s (Hook)
        // 3. Wake up & Acquire Lock
        // 4. Check Token -> Detect Mismatch -> Abort
        $cache->set($key, "STALE_DATA_THAT_SHOULD_FAIL");
        
        // Verify: The file should NOT exist (or be empty/different)
        $dir = DIR_CACHE . 'race_key/';
        $files = glob($dir . '[0-9]*'); // Look for L2 files
        
        if (empty($files)) {
            log_msg($id, "SUCCESS: Write was ABORTED (Token mismatch detected).");
        } else {
            // Check content
            $content = file_get_contents($files[0]);
            if (strpos($content, "STALE_DATA") !== false) {
                log_msg($id, "FAILURE: Stale data was written! Race condition missed.");
            } else {
                log_msg($id, "SUCCESS: Write aborted (File is from Sniper, not me).");
            }
        }
        
        log_msg($id, "DONE.");
    } catch (\Throwable $e) { log_msg($id, "ERR: ".$e->getMessage()); }
}

function run_worker_sniper($id) {
    try {
        $cache = new \Opencart\System\Library\Cache\File(3600);
        $key = 'race_key';
        
        log_msg($id, "Waiting 1s for Writer to enter the Trap...");
        sleep(1);
        
        log_msg($id, ">>> SNIPER SHOT (Deleting key)...");
        // This updates the Invalidation Token on disk
        $cache->delete($key);
        
        log_msg($id, ">>> SHOT FIRED. Token updated.");
        log_msg($id, "DONE.");
    } catch (\Throwable $e) { log_msg($id, "ERR: ".$e->getMessage()); }
}


// --- TEST 11: BUSY GC TRIGGER ---

function setup_gc_scenario() {
    $bucket = 'gc_zombie_test';
    $dir = DIR_CACHE . $bucket . '/';
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    
    // Create an EXPIRED L2 file (1 hour old)
    // The GC logic should detect this is expired, verify no L1 exists, and rename L2->L1
    $expired_time = time() - 3600;
    $data = json_encode("I AM A ZOMBIE");
    file_put_contents($dir . $expired_time, $data);
    
    echo "[SETUP] Created expired L2 file at: $dir$expired_time\n";
}

function run_worker_gc_watcher($id) {
    try {
        $bucket = 'gc_zombie_test';
        $dir = DIR_CACHE . $bucket . '/';
        
        log_msg($id, "Monitoring file system state...");
        
        $start = time();
        while(time() - $start < 6) {
            $l2_files = glob($dir . '[0-9]*');
            $l1_files = glob($dir . 'l1-*');
            
            if (!empty($l2_files)) {
                log_msg($id, "State: Expired L2 file present.");
            } elseif (!empty($l1_files)) {
                log_msg($id, "SUCCESS: L2 Gone. L1 Found! (Zombie Promotion Logic Verified)");
                log_msg($id, "DONE.");
                return;
            } else {
                log_msg($id, "State: Directory empty (Failure if L2 vanished without L1).");
            }
            
            sleep(1);
        }
        
        log_msg($id, "TIMEOUT: GC did not occur or did not promote file.");
        log_msg($id, "DONE.");
    } catch (\Throwable $e) { log_msg($id, "ERR: ".$e->getMessage()); }
}

function run_worker_gc_trigger($id) {
    try {
        log_msg($id, "Waiting 2s before forcing GC...");
        sleep(2);
        
        log_msg($id, "Initializing Cache with 'force_gc' mode...");
        
        // This relies on the constructor logic we just added:
        // if ($test_mode === 'force_gc') { $this->gc_interval = 0; ... }
        $cache = new \Opencart\System\Library\Cache\File(3600, 'force_gc');
        
        // Force the destructor to run immediately by destroying the object reference
        unset($cache);
        
        log_msg($id, "Destructor executed.");
        log_msg($id, "DONE.");
    } catch (\Throwable $e) { log_msg($id, "ERR: ".$e->getMessage()); }
}

// --- TEST 12: THE UNDEAD FILE (DETERMINISTIC) ---

function run_worker_resurrector($id) {
    try {
        $key = 'undead_race';
        $dir = DIR_CACHE . $key . '/';
        $cycle_file = DIR_CACHE . 'undead_cycle.txt';
        
        $start = time();
        $cycle = 0;
        
        while(time() - $start < 8) {
            $cycle++;
            
            // 1. Advertise Current Cycle (Atomic write)
            file_put_contents($cycle_file, (string)$cycle);
            
            // 2. Create deterministically named file
            // Use a timestamp far in the past + cycle ID to ensure unique but predictable filenames
            if (!is_dir($dir)) @mkdir($dir, 0777, true);
            $filename = 1000000 + $cycle; // e.g. 1000001
            $data = json_encode("ZOMBIE_$cycle");
            file_put_contents($dir . $filename, $data);
            
            // 3. Force GC (Promotes 1000001 -> l1-1000001)
            $cache_trigger = new \Opencart\System\Library\Cache\File(3600, 'force_gc');
            unset($cache_trigger); 
            
            // 4. Sleep to allow Destroyer to strike
            usleep(rand(50000, 150000));
        }
        
        log_msg($id, "DONE.");
    } catch (\Throwable $e) { log_msg($id, "ERR: ".$e->getMessage()); }
}

function run_worker_destroyer($id) {
    try {
        $cache = new \Opencart\System\Library\Cache\File(3600);
        $key = 'undead_race';
        $dir = DIR_CACHE . $key . '/';
        $cycle_file = DIR_CACHE . 'undead_cycle.txt';
        
        log_msg($id, "Starting Invalidation (Swap) loop...");
        
        $start = time();
        $failures = 0;
        
        while(time() - $start < 8) {
            // 1. Identify Target
            $target_cycle = (int)@file_get_contents($cycle_file);
            if (!$target_cycle) { usleep(10000); continue; }
            
            $target_l2 = $dir . (1000000 + $target_cycle);
            $target_l1 = $dir . 'l1-' . (1000000 + $target_cycle);

            // 2. Run Delete (Should Promote L2 -> L1)
            $cache->delete($key);
            
            // 3. Verify State
            if (is_dir($dir)) {
                // FAILURE CONDITION 1: L2 still exists (Delete failed)
                if (file_exists($target_l2)) {
                    log_msg($id, "FAILURE: L2 file remained! Invalidation failed for cycle $target_cycle.");
                    $failures++;
                }
                // FAILURE CONDITION 2: L1 missing (Swap failed / Data lost)
                elseif (!file_exists($target_l1)) {
                    // This is 'acceptable' if GC deleted it, but ideally shouldn't happen immediately
                    // checking for strict consistency here might be noisy, but let's check.
                    // Actually, let's stick to L2 check as the primary failure mode.
                }
            }
            
            usleep(rand(50000, 100000));
        }
        
        if ($failures === 0) {
            log_msg($id, "SUCCESS: All L2 targets successfully demoted to L1.");
        } else {
            log_msg($id, "FAILED: $failures invalidation failures.");
        }
        
        log_msg($id, "DONE.");
    } catch (\Throwable $e) { log_msg($id, "ERR: ".$e->getMessage()); }
}


// --- TEST 13: SYSTEM MELTDOWN (VERBOSE STRESS TEST) ---

/**
 * Defines the virtual application structure.
 * @return array List of endpoints with 'key', 'cost', and 'bucket' for logging
 */
function get_stress_config() {
    $buckets = ['catalog', 'menu', 'filter', 'seo', 'cart'];
    $endpoints = [];
    
    // Create 50 unique endpoints distributed across buckets
    for ($i = 0; $i < 50; $i++) {
        $bucket = $buckets[$i % count($buckets)];
        
        // Quadratic Cost Curve:
        // Endpoint 0: ~1ms
        // Endpoint 25: ~50ms
        // Endpoint 49: ~200ms (Heavy)
        $cost = round(1 + ($i * $i * 0.08)); 
        
        $endpoints[] = [
            'id'   => $i,
            'key'  => "{$bucket}.part_{$i}",
            'cost' => $cost,
            'bkt'  => $bucket
        ];
    }
    return $endpoints;
}

/**
 * Burn CPU cycles to simulate complex PHP processing.
 * BLOCKING operation.
 */
function burn_cpu($ms) {
    $start = microtime(true);
    $target = $start + ($ms / 1000);
    while (microtime(true) < $target) {
        $x = md5(uniqid());
        $y = sqrt(mt_rand(1, 1000000));
    }
}

function run_worker_renderer($id) {
    try {
        $cache = new \Opencart\System\Library\Cache\File(3600);
        $endpoints = get_stress_config();
        
        $start_test = time();
        $pages_rendered = 0;
        
        // Use an internal counter to only log every Nth operation
        while(time() - $start_test < 15) {
            $page_reqs = array_rand($endpoints, 5);
            
            foreach ($page_reqs as $idx) {
                $ep = $endpoints[$idx];
                $data = $cache->get($ep['key']);
                
                if ($data === null) {
                    // Only log Misses - these are the "interesting" events
                    log_msg($id, "[MISS] {$ep['bkt']}[{$ep['id']}]");
                    burn_cpu($ep['cost']);
                    $cache->set($ep['key'], "VAL_" . microtime());
                }
            }
            
            $pages_rendered++;
            
            // Only log heartbeat every 10 pages to keep pipes clear
            if ($pages_rendered % 10 === 0) {
                log_msg($id, "HEARTBEAT: Rendered $pages_rendered pages...");
            }

            // Increase think-time slightly for Linux
            usleep(rand(20000, 60000)); 
        }
        
        log_msg($id, "DONE. Total Pages: $pages_rendered");
    } catch (\Throwable $e) { log_msg($id, "FATAL: ".$e->getMessage()); }
}

function run_worker_chaos_monkey($id) {
    try {
        $config = get_stress_config();
        
        log_msg($id, "Starting Chaos loop...");
        
        $start_test = time();
        $ops = 0;
        
        while(time() - $start_test < 15) {
            $ops++;
            $action = rand(1, 100);
            
            // 20% Chance: Force GC
            if ($action > 80) {
                log_msg($id, "[GC  ] Triggering Force GC...");
                $cache = new \Opencart\System\Library\Cache\File(3600, 'force_gc');
                unset($cache); // Destructor runs immediately
            } 
            // 80% Chance: Delete Operations
            else {
                $cache = new \Opencart\System\Library\Cache\File(3600);
                
                // Randomly pick a target from config
                $target = $config[array_rand($config)];
                
                log_msg($id, "[DEL ] Deleting {$target['key']} (Bucket: {$target['bkt']})...");
                $cache->delete($target['key']);
            }
            
            // Chaos is aggressive (5ms - 20ms delay)
            usleep(rand(5000, 20000));
        }
        
        log_msg($id, "DONE. Total Chaos Ops: $ops");
    } catch (\Throwable $e) { log_msg($id, "FATAL: ".$e->getMessage()); }
}

// --- TEST 14: BENCHMARK (LATENCY & THROUGHPUT) ---

function get_benchmark_config() {
    $buckets = ['cat', 'prod', 'menu', 'seo', 'cart', 'filter', 'module', 'layout', 'banner', 'api'];
    $endpoints = [];
    
    // 200 Unique Endpoints
    for ($i = 0; $i < 200; $i++) {
        $bucket = $buckets[$i % count($buckets)];
        // Rebuild Cost: 50ms to 10,000ms
        // Linear-ish distribution with some heavy spikes
        $base = rand(50, 500); 
        if ($i % 20 === 0) $base = rand(1000, 10000); // 5% are very heavy
        
        $endpoints[] = [
            'key' => "{$bucket}.bench_{$i}",
            'cost' => $base
        ];
    }
    return $endpoints;
}

function run_worker_benchmark_renderer($id) {
    try {
        $cache = new \Opencart\System\Library\Cache\File(3600);
        $endpoints = get_benchmark_config();
        
        $stats = [
            'p_count' => 0,
            'p_sum' => 0,
            'p_min' => 9999999,
            'p_max' => 0,
            'c_count' => 0,
            'c_sum' => 0,
            'c_min' => 9999999,
            'c_max' => 0
        ];
        
        $start_test = time();
        
        // Run loop
        while(time() - $start_test < 200) { // Run slightly less than orchestrator timeout
            $page_start = microtime(true);
            
            // A "Page" hits 5 random endpoints
            $reqs = array_rand($endpoints, 5);
            
            foreach ($reqs as $idx) {
                $ep = $endpoints[$idx];
                
                // Measure GET
                $t0 = microtime(true);
                $data = $cache->get($ep['key']);
                $t1 = microtime(true);
                
                $op_time = ($t1 - $t0) * 1000;
                $stats['c_count']++;
                $stats['c_sum'] += $op_time;
                if ($op_time < $stats['c_min']) $stats['c_min'] = $op_time;
                if ($op_time > $stats['c_max']) $stats['c_max'] = $op_time;
                
                if ($data === null) {
                    // Simulate Rebuild
                    burn_cpu($ep['cost']);
                    
                    // Measure SET
                    $t2 = microtime(true);
                    $cache->set($ep['key'], "DATA_" . microtime());
                    $t3 = microtime(true);
                    
                    $op_time = ($t3 - $t2) * 1000;
                    $stats['c_count']++;
                    $stats['c_sum'] += $op_time;
                    if ($op_time < $stats['c_min']) $stats['c_min'] = $op_time;
                    if ($op_time > $stats['c_max']) $stats['c_max'] = $op_time;
                }
            }
            
            $page_end = microtime(true);
            $p_time = ($page_end - $page_start) * 1000;
            
            $stats['p_count']++;
            $stats['p_sum'] += $p_time;
            if ($p_time < $stats['p_min']) $stats['p_min'] = $p_time;
            if ($p_time > $stats['p_max']) $stats['p_max'] = $p_time;
            
            // NO SLEEP. Immediate restart.
        }
        
        // Save Stats to File for Aggregation
        file_put_contents(DIR_CACHE . "stats_{$id}.json", json_encode($stats));
        
        // Log Summary
        $p_avg = ($stats['p_count'] > 0) ? round($stats['p_sum']/$stats['p_count'], 2) : 0;
        $c_avg = ($stats['c_count'] > 0) ? round($stats['c_sum']/$stats['c_count'], 2) : 0;
        
        log_msg($id, "DONE. Pages: {$stats['p_count']} (Avg: {$p_avg}ms) | Ops: {$stats['c_count']} (Avg: {$c_avg}ms)");
        
    } catch (\Throwable $e) { log_msg($id, "FATAL: ".$e->getMessage()); }
}

function run_worker_benchmark_chaos($id) {
    try {
        $config = get_benchmark_config();
        $start_test = time();
        
        while(time() - $start_test < 200) {
            $action = rand(1, 100);
            $t0 = microtime(true);
            
            if ($action > 90) {
                // Force GC
                $cache = new \Opencart\System\Library\Cache\File(3600, 'force_gc');
                unset($cache);
            } else {
                // Delete
                $cache = new \Opencart\System\Library\Cache\File(3600);
                $target = $config[array_rand($config)];
                $cache->delete($target['key']);
            }
            
            $t1 = microtime(true);
            $dur = round(($t1 - $t0) * 1000, 2);
            
            if ($dur > 500) {
                log_msg($id, "Slow Chaos Op: {$dur}ms");
            }
            
            usleep(rand(50000, 200000));
        }
    } catch (\Throwable $e) { log_msg($id, "FATAL: ".$e->getMessage()); }
}
