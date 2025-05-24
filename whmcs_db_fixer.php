<?php
/**
 * WHMCS Database Structure Fixer - Advanced Edition
 * 
 * Purpose: Automatically scan, detect, and fix structural issues in WHMCS database tables
 * Focus: AUTO_INCREMENT restoration, duplicate entry fixes, schema validation
 * 
 * Author: Database Repair Specialist
 * Version: 2.1.0
 * Last Updated: 2025
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(0);

class WHMCSDBFixer {
    
    private $db;
    private $config;
    private $logFile;
    private $backupDir;
    private $dryRun;
    private $verbose;
    private $fixedTables = [];
    private $skippedTables = [];
    private $errors = [];
    private $lastConnectionTime;
    private $connectionRetries = 0;
    
    // WHMCS Core Tables that commonly need AUTO_INCREMENT fixes
    private $coreWHMCSTables = [
        'tblclients', 'tblorders', 'tblhostingaccounts', 'tbldomains', 
        'tblinvoices', 'tblinvoiceitems', 'tbltickets', 'tblticketreplies',
        'tblproducts', 'tblproductgroups', 'tblhosting', 'tblaccounts',
        'tbladmins', 'tblaffiliates', 'tblaffiliatespayments', 'tblannouncements',
        'tblbannedips', 'tblconfiguration', 'tblcurrencies', 'tblcustomfields',
        'tblcustomfieldsvalues', 'tblemails', 'tblemailtemplates', 'tblgateways',
        'tblknowledgebase', 'tbllinks', 'tblnetworkissues', 'tblpaymentgateways',
        'tblpricing', 'tblquotes', 'tblservers', 'tblservices', 'tblsupportdepartments',
        'tbltax', 'tbltodolist', 'tbltransactions', 'tblusers', 'tblactivitylog'
    ];
    
    public function __construct($config = []) {
        $this->config = array_merge([
            'host' => 'localhost',
                'username' => 'shahidmaladbusername',
                'password' => 'shahidmallapassword',
                'database' => 'shahidmalladb,
            'charset' => 'utf8mb4',
            'dry_run' => false,
            'verbose' => true,
            'backup_enabled' => true,
            'log_enabled' => true,
            'max_id_gap' => 1000000, // Skip tables with ID gaps larger than this
            'batch_size' => 1000,
            'connection_timeout' => 3600, // 1 hour
            'max_retries' => 3,
            'retry_delay' => 2, // seconds
            'memory_limit' => '512M',
            'max_execution_time' => 7200, // 2 hours
            'reconnect_interval' => 300, // Reconnect every 5 minutes
        ], $config);
        
        $this->dryRun = $this->config['dry_run'];
        $this->verbose = $this->config['verbose'];
        
        // Set PHP limits
        ini_set('memory_limit', $this->config['memory_limit']);
        set_time_limit($this->config['max_execution_time']);
        
        // Track connection time for auto-reconnect
        $this->lastConnectionTime = time();
        
        // Initialize logging
        if ($this->config['log_enabled']) {
            $this->logFile = 'whmcs_db_fixer_' . date('Y-m-d_H-i-s') . '.log';
            $this->log("=== WHMCS Database Fixer Started ===");
            $this->log("Mode: " . ($this->dryRun ? "DRY RUN" : "LIVE"));
        }
        
        // Initialize backup directory
        if ($this->config['backup_enabled']) {
            $this->backupDir = 'db_backups_' . date('Y-m-d_H-i-s');
            if (!$this->dryRun && !is_dir($this->backupDir)) {
                mkdir($this->backupDir, 0755, true);
            }
        }
        
        $this->connectDatabase();
    }
    
    private function connectDatabase() {
        try {
            $dsn = "mysql:host={$this->config['host']};dbname={$this->config['database']};charset={$this->config['charset']}";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => $this->config['connection_timeout'],
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY','')), SESSION wait_timeout=28800, SESSION interactive_timeout=28800",
                PDO::MYSQL_ATTR_RECONNECT => true,
                PDO::ATTR_PERSISTENT => false
            ];
            
            $this->db = new PDO($dsn, $this->config['username'], $this->config['password'], $options);
            $this->lastConnectionTime = time();
            $this->connectionRetries = 0;
            
            // Additional MySQL settings for stability
            $this->db->exec("SET SESSION max_allowed_packet=1073741824"); // 1GB
            $this->db->exec("SET SESSION net_read_timeout=600");
            $this->db->exec("SET SESSION net_write_timeout=600");
            
            $this->log("✅ Database connection established successfully");
        } catch (PDOException $e) {
            $this->log("❌ Database connection failed: " . $e->getMessage());
            throw new Exception("Database connection failed: " . $e->getMessage());
        }
    }
    
    private function ensureConnection() {
        // Check if we need to reconnect
        $timeSinceLastConnection = time() - $this->lastConnectionTime;
        
        if ($timeSinceLastConnection > $this->config['reconnect_interval']) {
            $this->log("🔄 Reconnecting to database (preventive reconnection)");
            $this->connectDatabase();
            return;
        }
        
        // Test connection
        try {
            $this->db->query("SELECT 1");
        } catch (PDOException $e) {
            if ($this->isConnectionError($e)) {
                $this->log("🔄 Connection lost, attempting to reconnect...");
                $this->connectDatabase();
            } else {
                throw $e;
            }
        }
    }
    
    private function isConnectionError($exception) {
        $connectionErrors = [
            'MySQL server has gone away',
            'Lost connection to MySQL server',
            'Connection timed out',
            'MySQL server has gone away during query'
        ];
        
        foreach ($connectionErrors as $error) {
            if (strpos($exception->getMessage(), $error) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    private function executeWithRetry($callback, $maxRetries = null) {
        $maxRetries = $maxRetries ?? $this->config['max_retries'];
        $attempt = 0;
        
        while ($attempt < $maxRetries) {
            try {
                $this->ensureConnection();
                return $callback();
            } catch (Exception $e) {
                $attempt++;
                
                if ($this->isConnectionError($e) && $attempt < $maxRetries) {
                    $this->log("⚠️  Connection error (attempt {$attempt}/{$maxRetries}): " . $e->getMessage());
                    $this->log("🔄 Waiting {$this->config['retry_delay']} seconds before retry...");
                    sleep($this->config['retry_delay']);
                    
                    try {
                        $this->connectDatabase();
                    } catch (Exception $connectError) {
                        $this->log("❌ Reconnection failed: " . $connectError->getMessage());
                        if ($attempt === $maxRetries - 1) {
                            throw $e;
                        }
                    }
                } else {
                    throw $e;
                }
            }
        }
        
        throw new Exception("Max retries exceeded");
    }
    
    public function scanAndFixDatabase() {
        $this->log("\n🔍 Starting comprehensive database scan...");
        
        // Get all tables in database
        $tables = $this->getAllTables();
        $this->log("Found " . count($tables) . " tables to analyze");
        
        $issuesFound = 0;
        $tablesFixed = 0;
        
        // Process tables in smaller batches to prevent timeouts
        $this->processTablesInBatches($tables, 5);
        
        // Count results
        $tablesFixed = count($this->fixedTables);
        $issuesFound = $tablesFixed + count($this->errors);
        
        $this->generateReport($tablesFixed, $issuesFound);
    }
    
    private function getAllTables() {
        return $this->executeWithRetry(function() {
            $stmt = $this->db->query("SHOW TABLES");
            $tables = [];
            while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                $tables[] = $row[0];
            }
            return $tables;
        });
    }
    
    private function analyzeTable($tableName) {
        $issues = [];
        
        // Get table structure
        $columns = $this->getTableColumns($tableName);
        $indexes = $this->getTableIndexes($tableName);
        
        // Check for primary key issues
        $primaryKey = $this->findPrimaryKeyColumn($columns, $indexes);
        
        if ($primaryKey) {
            // Check if primary key lacks AUTO_INCREMENT
            if (!$this->hasAutoIncrement($tableName, $primaryKey)) {
                $issues['missing_auto_increment'] = [
                    'column' => $primaryKey,
                    'description' => "Primary key '{$primaryKey}' missing AUTO_INCREMENT"
                ];
            }
            
            // Check for zero/duplicate IDs
            $zeroIdCount = $this->countZeroIds($tableName, $primaryKey);
            if ($zeroIdCount > 0) {
                $issues['zero_ids'] = [
                    'column' => $primaryKey,
                    'count' => $zeroIdCount,
                    'description' => "Found {$zeroIdCount} rows with {$primaryKey} = 0"
                ];
            }
            
            // Check for duplicate IDs
            $duplicates = $this->findDuplicateIds($tableName, $primaryKey);
            if (!empty($duplicates)) {
                $issues['duplicate_ids'] = [
                    'column' => $primaryKey,
                    'duplicates' => $duplicates,
                    'description' => "Found duplicate IDs: " . implode(', ', array_keys($duplicates))
                ];
            }
        }
        
        return $issues;
    }
    
    private function getTableColumns($tableName) {
        return $this->executeWithRetry(function() use ($tableName) {
            $stmt = $this->db->prepare("DESCRIBE `{$tableName}`");
            $stmt->execute();
            return $stmt->fetchAll();
        });
    }
    
    private function getTableIndexes($tableName) {
        return $this->executeWithRetry(function() use ($tableName) {
            $stmt = $this->db->prepare("SHOW INDEX FROM `{$tableName}`");
            $stmt->execute();
            return $stmt->fetchAll();
        });
    }
    
    private function findPrimaryKeyColumn($columns, $indexes) {
        // First check for PRIMARY key in indexes
        foreach ($indexes as $index) {
            if ($index['Key_name'] === 'PRIMARY') {
                return $index['Column_name'];
            }
        }
        
        // Fallback: look for 'id' column or first column with PRI key
        foreach ($columns as $column) {
            if ($column['Key'] === 'PRI') {
                return $column['Field'];
            }
        }
        
        return null;
    }
    
    private function hasAutoIncrement($tableName, $columnName) {
        return $this->executeWithRetry(function() use ($tableName, $columnName) {
            $stmt = $this->db->prepare("SHOW COLUMNS FROM `{$tableName}` LIKE ?");
            $stmt->execute([$columnName]);
            $column = $stmt->fetch();
            
            return $column && stripos($column['Extra'], 'auto_increment') !== false;
        });
    }
    
    private function countZeroIds($tableName, $primaryKey) {
        try {
            return $this->executeWithRetry(function() use ($tableName, $primaryKey) {
                $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM `{$tableName}` WHERE `{$primaryKey}` = 0");
                $stmt->execute();
                $result = $stmt->fetch();
                return (int)$result['count'];
            });
        } catch (Exception $e) {
            $this->log("Warning: Could not count zero IDs in {$tableName}: " . $e->getMessage());
            return 0;
        }
    }
    
    private function findDuplicateIds($tableName, $primaryKey) {
        try {
            return $this->executeWithRetry(function() use ($tableName, $primaryKey) {
                $stmt = $this->db->prepare("
                    SELECT `{$primaryKey}`, COUNT(*) as count 
                    FROM `{$tableName}` 
                    WHERE `{$primaryKey}` > 0
                    GROUP BY `{$primaryKey}` 
                    HAVING count > 1 
                    LIMIT 50
                ");
                $stmt->execute();
                
                $duplicates = [];
                while ($row = $stmt->fetch()) {
                    $duplicates[$row[$primaryKey]] = $row['count'];
                }
                return $duplicates;
            });
        } catch (Exception $e) {
            $this->log("Warning: Could not check for duplicates in {$tableName}: " . $e->getMessage());
            return [];
        }
    }
    
    private function fixTableIssues($tableName, $issues) {
        $success = true;
        
        foreach ($issues as $issueType => $issueData) {
            switch ($issueType) {
                case 'zero_ids':
                    $success &= $this->fixZeroIds($tableName, $issueData);
                    break;
                case 'duplicate_ids':
                    $success &= $this->fixDuplicateIds($tableName, $issueData);
                    break;
                case 'missing_auto_increment':
                    $success &= $this->fixAutoIncrement($tableName, $issueData);
                    break;
            }
        }
        
        return $success;
    }
    
    private function fixZeroIds($tableName, $issueData) {
        $column = $issueData['column'];
        $count = $issueData['count'];
        
        $this->log("🔧 Fixing {$count} zero ID rows in {$tableName}...");
        
        if ($this->dryRun) {
            $this->log("DRY RUN: Would delete {$count} rows with {$column} = 0");
            return true;
        }
        
        try {
            // Backup zero ID rows before deletion
            if ($this->config['backup_enabled']) {
                $this->executeWithRetry(function() use ($tableName, $column) {
                    $this->backupZeroIdRows($tableName, $column);
                });
            }
            
            // Delete zero ID rows with retry
            $deletedRows = $this->executeWithRetry(function() use ($tableName, $column) {
                $stmt = $this->db->prepare("DELETE FROM `{$tableName}` WHERE `{$column}` = 0");
                $stmt->execute();
                return $stmt->rowCount();
            });
            
            $this->log("✅ Deleted {$deletedRows} zero ID rows from {$tableName}");
            
            return true;
        } catch (Exception $e) {
            $this->log("❌ Failed to fix zero IDs in {$tableName}: " . $e->getMessage());
            return false;
        }
    }
    
    private function fixDuplicateIds($tableName, $issueData) {
        $column = $issueData['column'];
        $duplicates = $issueData['duplicates'];
        
        $this->log("🔧 Fixing duplicate IDs in {$tableName}...");
        
        if ($this->dryRun) {
            $this->log("DRY RUN: Would fix duplicates: " . implode(', ', array_keys($duplicates)));
            return true;
        }
        
        try {
            foreach ($duplicates as $duplicateId => $count) {
                // Keep the first row, delete others with retry
                $this->executeWithRetry(function() use ($tableName, $column, $duplicateId, $count) {
                    $stmt = $this->db->prepare("
                        DELETE FROM `{$tableName}` 
                        WHERE `{$column}` = ? 
                        ORDER BY `{$column}` ASC 
                        LIMIT " . ($count - 1)
                    );
                    $stmt->execute([$duplicateId]);
                    return $stmt->rowCount();
                });
                
                $this->log("✅ Removed " . ($count - 1) . " duplicate rows for ID {$duplicateId}");
            }
            
            return true;
        } catch (Exception $e) {
            $this->log("❌ Failed to fix duplicates in {$tableName}: " . $e->getMessage());
            return false;
        }
    }
    
    private function fixAutoIncrement($tableName, $issueData) {
        $column = $issueData['column'];
        
        $this->log("🔧 Adding AUTO_INCREMENT to {$tableName}.{$column}...");
        
        if ($this->dryRun) {
            $this->log("DRY RUN: Would add AUTO_INCREMENT to {$tableName}.{$column}");
            return true;
        }
        
        try {
            // Get the current maximum ID with retry
            $result = $this->executeWithRetry(function() use ($tableName, $column) {
                $stmt = $this->db->prepare("SELECT MAX(`{$column}`) as max_id FROM `{$tableName}`");
                $stmt->execute();
                return $stmt->fetch();
            });
            
            $nextAutoIncrement = ((int)$result['max_id']) + 1;
            
            // Get column definition with retry
            $columnDef = $this->executeWithRetry(function() use ($tableName, $column) {
                return $this->getColumnDefinition($tableName, $column);
            });
            
            // Modify column to add AUTO_INCREMENT with retry
            $this->executeWithRetry(function() use ($tableName, $column, $columnDef, $nextAutoIncrement) {
                $sql = "ALTER TABLE `{$tableName}` MODIFY `{$column}` {$columnDef} AUTO_INCREMENT";
                $this->db->exec($sql);
                
                // Set AUTO_INCREMENT starting value
                $sql = "ALTER TABLE `{$tableName}` AUTO_INCREMENT = {$nextAutoIncrement}";
                $this->db->exec($sql);
            });
            
            $this->log("✅ Added AUTO_INCREMENT to {$tableName}.{$column}, starting from {$nextAutoIncrement}");
            
            return true;
        } catch (Exception $e) {
            $this->log("❌ Failed to add AUTO_INCREMENT to {$tableName}.{$column}: " . $e->getMessage());
            return false;
        }
    }
    
    private function getColumnDefinition($tableName, $columnName) {
        return $this->executeWithRetry(function() use ($tableName, $columnName) {
            $stmt = $this->db->prepare("SHOW COLUMNS FROM `{$tableName}` LIKE ?");
            $stmt->execute([$columnName]);
            $column = $stmt->fetch();
            
            if (!$column) {
                throw new Exception("Column {$columnName} not found in {$tableName}");
            }
            
            // Build column definition
            $definition = $column['Type'];
            
            if ($column['Null'] === 'NO') {
                $definition .= ' NOT NULL';
            }
            
            if ($column['Default'] !== null) {
                $definition .= " DEFAULT '" . $column['Default'] . "'";
            }
            
            return $definition;
        });
    }
    
    private function backupZeroIdRows($tableName, $column) {
        try {
            $this->executeWithRetry(function() use ($tableName, $column) {
                $backupFile = $this->backupDir . "/{$tableName}_zero_ids.sql";
                
                $stmt = $this->db->prepare("SELECT * FROM `{$tableName}` WHERE `{$column}` = 0");
                $stmt->execute();
                
                $backup = "-- Backup of zero ID rows from {$tableName}\n";
                $backup .= "-- Created: " . date('Y-m-d H:i:s') . "\n\n";
                
                while ($row = $stmt->fetch()) {
                    $columns = array_keys($row);
                    $values = array_map(function($val) {
                        return $val === null ? 'NULL' : "'" . addslashes($val) . "'";
                    }, array_values($row));
                    
                    $backup .= "INSERT INTO `{$tableName}` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $values) . ");\n";
                }
                
                file_put_contents($backupFile, $backup);
                $this->log("💾 Backed up zero ID rows to {$backupFile}");
            });
        } catch (Exception $e) {
            $this->log("Warning: Could not backup zero ID rows: " . $e->getMessage());
        }
    }
    
    private function generateReport($tablesFixed, $issuesFound) {
        $this->log("\n" . str_repeat("=", 60));
        $this->log("📊 WHMCS DATABASE FIXER REPORT");
        $this->log(str_repeat("=", 60));
        $this->log("Mode: " . ($this->dryRun ? "DRY RUN" : "LIVE EXECUTION"));
        $this->log("Date: " . date('Y-m-d H:i:s'));
        $this->log("Issues Found: {$issuesFound}");
        $this->log("Tables Fixed: {$tablesFixed}");
        $this->log("Tables Skipped: " . count($this->skippedTables));
        $this->log("Errors: " . count($this->errors));
        
        if (!empty($this->fixedTables)) {
            $this->log("\n✅ Fixed Tables:");
            foreach ($this->fixedTables as $table) {
                $this->log("  - {$table}");
            }
        }
        
        if (!empty($this->skippedTables)) {
            $this->log("\n⏭️  Skipped Tables:");
            foreach ($this->skippedTables as $table) {
                $this->log("  - {$table}");
            }
        }
        
        if (!empty($this->errors)) {
            $this->log("\n❌ Errors:");
            foreach ($this->errors as $error) {
                $this->log("  - {$error}");
            }
        }
        
        $this->log("\n" . str_repeat("=", 60));
        
        // Generate HTML report for web interface
        if (!$this->dryRun) {
            $this->generateHTMLReport($tablesFixed, $issuesFound);
        }
    }
    
    private function generateHTMLReport($tablesFixed, $issuesFound) {
        $html = "<!DOCTYPE html>
<html>
<head>
    <title>WHMCS Database Fixer Report</title>
    <style>
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            margin: 0; 
            padding: 20px; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .header { 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white; 
            padding: 30px; 
            text-align: center;
            position: relative;
        }
        .header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 100 100\"><defs><pattern id=\"grain\" width=\"100\" height=\"100\" patternUnits=\"userSpaceOnUse\"><circle cx=\"25\" cy=\"25\" r=\"1\" fill=\"white\" opacity=\"0.1\"/><circle cx=\"75\" cy=\"75\" r=\"1\" fill=\"white\" opacity=\"0.1\"/><circle cx=\"50\" cy=\"10\" r=\"0.5\" fill=\"white\" opacity=\"0.1\"/><circle cx=\"20\" cy=\"80\" r=\"0.5\" fill=\"white\" opacity=\"0.1\"/></pattern></defs><rect width=\"100\" height=\"100\" fill=\"url(%23grain)\"/></svg>') repeat;
        }
        .header h1 {
            margin: 0;
            font-size: 2.5em;
            font-weight: 300;
            position: relative;
            z-index: 1;
        }
        .header p {
            margin: 10px 0 0 0;
            opacity: 0.9;
            font-size: 1.1em;
            position: relative;
            z-index: 1;
        }
        .content {
            padding: 30px;
        }
        .stats { 
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px; 
            margin: 30px 0;
        }
        .stat-box { 
            background: #f8f9fa;
            padding: 25px; 
            border-radius: 10px; 
            text-align: center;
            border-left: 5px solid #e9ecef;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .stat-box:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        .stat-box h3 {
            margin: 0 0 10px 0;
            font-size: 2.5em;
            font-weight: bold;
        }
        .stat-box p {
            margin: 0;
            color: #6c757d;
            font-size: 1.1em;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .success { 
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            border-left-color: #155724;
        }
        .success h3, .success p { color: white; }
        .warning { 
            background: linear-gradient(135deg, #ffc107, #fd7e14);
            color: white;
            border-left-color: #856404;
        }
        .warning h3, .warning p { color: white; }
        .error { 
            background: linear-gradient(135deg, #dc3545, #e83e8c);
            color: white;
            border-left-color: #721c24;
        }
        .error h3, .error p { color: white; }
        .info {
            background: linear-gradient(135deg, #17a2b8, #6f42c1);
            color: white;
            border-left-color: #0c5460;
        }
        .info h3, .info p { color: white; }
        .section {
            margin: 30px 0;
            background: #f8f9fa;
            border-radius: 10px;
            overflow: hidden;
        }
        .section-header {
            background: #343a40;
            color: white;
            padding: 15px 25px;
            font-weight: bold;
            font-size: 1.2em;
        }
        .section-content {
            padding: 25px;
        }
        .table-list { 
            background: transparent;
            padding: 0;
            border-radius: 0;
            margin: 0;
        }
        .table-list ul { 
            list-style-type: none; 
            padding: 0; 
            margin: 0;
        }
        .table-list li { 
            padding: 12px 20px; 
            border-bottom: 1px solid #dee2e6;
            display: flex;
            align-items: center;
            transition: background-color 0.3s ease;
        }
        .table-list li:hover {
            background-color: #e9ecef;
        }
        .table-list li:last-child {
            border-bottom: none;
        }
        .table-list li::before {
            content: '✓';
            color: #28a745;
            font-weight: bold;
            margin-right: 10px;
            font-size: 1.2em;
        }
        .error-list li::before {
            content: '✗';
            color: #dc3545;
        }
        .skip-list li::before {
            content: '⏭';
            color: #ffc107;
        }
        .summary {
            background: linear-gradient(135deg, #6c757d, #495057);
            color: white;
            padding: 25px;
            border-radius: 10px;
            margin: 30px 0;
            text-align: center;
        }
        .summary h3 {
            margin: 0 0 15px 0;
            font-size: 1.5em;
        }
        .summary p {
            margin: 5px 0;
            font-size: 1.1em;
        }
        .footer {
            text-align: center;
            padding: 20px;
            color: #6c757d;
            border-top: 1px solid #dee2e6;
            margin-top: 40px;
        }
        @media (max-width: 768px) {
            .stats {
                grid-template-columns: 1fr;
            }
            .header h1 {
                font-size: 2em;
            }
            .content {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>🔧 WHMCS Database Fixer Report</h1>
            <p>Generated: " . date('Y-m-d H:i:s') . " | Mode: " . ($this->dryRun ? "DRY RUN" : "LIVE EXECUTION") . "</p>
        </div>
        
        <div class='content'>
            <div class='stats'>
                <div class='stat-box success'>
                    <h3>{$tablesFixed}</h3>
                    <p>Tables Fixed</p>
                </div>
                <div class='stat-box warning'>
                    <h3>{$issuesFound}</h3>
                    <p>Issues Found</p>
                </div>
                <div class='stat-box error'>
                    <h3>" . count($this->errors) . "</h3>
                    <p>Errors</p>
                </div>
                <div class='stat-box info'>
                    <h3>" . count($this->skippedTables) . "</h3>
                    <p>Skipped</p>
                </div>
            </div>
            
            <div class='summary'>
                <h3>🎯 Execution Summary</h3>
                <p><strong>Database:</strong> {$this->config['database']}</p>
                <p><strong>Total Tables Processed:</strong> " . (count($this->fixedTables) + count($this->skippedTables) + count($this->errors)) . "</p>
                <p><strong>Success Rate:</strong> " . round((count($this->fixedTables) / max(1, count($this->fixedTables) + count($this->errors))) * 100, 1) . "%</p>
            </div>";
        
        if (!empty($this->fixedTables)) {
            $html .= "
            <div class='section'>
                <div class='section-header'>
                    ✅ Successfully Fixed Tables (" . count($this->fixedTables) . ")
                </div>
                <div class='section-content'>
                    <div class='table-list'>
                        <ul>";
            foreach ($this->fixedTables as $table) {
                $html .= "<li>{$table}</li>";
            }
            $html .= "
                        </ul>
                    </div>
                </div>
            </div>";
        }
        
        if (!empty($this->skippedTables)) {
            $html .= "
            <div class='section'>
                <div class='section-header'>
                    ⏭️ Skipped Tables (" . count($this->skippedTables) . ")
                </div>
                <div class='section-content'>
                    <div class='table-list skip-list'>
                        <ul>";
            foreach ($this->skippedTables as $table) {
                $html .= "<li>{$table}</li>";
            }
            $html .= "
                        </ul>
                    </div>
                </div>
            </div>";
        }
        
        if (!empty($this->errors)) {
            $html .= "
            <div class='section'>
                <div class='section-header'>
                    ❌ Errors Encountered (" . count($this->errors) . ")
                </div>
                <div class='section-content'>
                    <div class='table-list error-list'>
                        <ul>";
            foreach ($this->errors as $error) {
                $html .= "<li>" . htmlspecialchars($error) . "</li>";
            }
            $html .= "
                        </ul>
                    </div>
                </div>
            </div>";
        }
        
        $html .= "
            <div class='footer'>
                <p>🔧 <strong>WHMCS Database Fixer v2.1.0</strong> - Advanced Database Repair Tool</p>
                <p>Report generated automatically | Check log files for detailed information</p>
            </div>
        </div>
    </div>
    
    <script>
        // Add some interactive effects
        document.addEventListener('DOMContentLoaded', function() {
            // Animate stat boxes
            const statBoxes = document.querySelectorAll('.stat-box');
            statBoxes.forEach((box, index) => {
                setTimeout(() => {
                    box.style.opacity = '0';
                    box.style.transform = 'translateY(20px)';
                    box.style.transition = 'all 0.6s ease';
                    
                    setTimeout(() => {
                        box.style.opacity = '1';
                        box.style.transform = 'translateY(0)';
                    }, 100);
                }, index * 200);
            });
            
            // Add click effects to table items
            const tableItems = document.querySelectorAll('.table-list li');
            tableItems.forEach(item => {
                item.addEventListener('click', function() {
                    this.style.transform = 'scale(0.98)';
                    setTimeout(() => {
                        this.style.transform = 'scale(1)';
                    }, 150);
                });
            });
        });
    </script>
</body>
</html>";
        
        file_put_contents('whmcs_db_fixer_report.html', $html);
        $this->log("📄 HTML report generated: whmcs_db_fixer_report.html");
    }
    
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$message}";
        
        if ($this->verbose) {
            echo $logMessage . "\n";
        }
        
        if ($this->logFile) {
            file_put_contents($this->logFile, $logMessage . "\n", FILE_APPEND | LOCK_EX);
        }
    }
    
    // Quick fix method for emergency situations
    public function quickFixCoreIssues() {
        $this->log("🚑 Running QUICK FIX for core WHMCS tables...");
        
        foreach ($this->coreWHMCSTables as $tableName) {
            if ($this->tableExists($tableName)) {
                $this->log("Quick fixing: {$tableName}");
                $issues = $this->analyzeTable($tableName);
                
                if (!empty($issues)) {
                    $this->fixTableIssues($tableName, $issues);
                }
            }
        }
    }
    
    private function tableExists($tableName) {
        try {
            return $this->executeWithRetry(function() use ($tableName) {
                $stmt = $this->db->prepare("SHOW TABLES LIKE ?");
                $stmt->execute([$tableName]);
                return $stmt->rowCount() > 0;
            });
        } catch (Exception $e) {
            return false;
        }
    }
    
    // Add emergency recovery method for severe connection issues
    public function emergencyRecovery() {
        $this->log("🚨 Starting EMERGENCY RECOVERY mode...");
        
        // Force reconnection
        try {
            $this->connectDatabase();
        } catch (Exception $e) {
            $this->log("❌ Emergency reconnection failed: " . $e->getMessage());
            return false;
        }
        
        // Try to fix only the most critical tables
        $criticalTables = ['tblclients', 'tblinvoices', 'tblorders', 'tbltickets', 'tblusers'];
        $fixedCount = 0;
        
        foreach ($criticalTables as $tableName) {
            if ($this->tableExists($tableName)) {
                try {
                    $this->log("🔧 Emergency fixing: {$tableName}");
                    $issues = $this->analyzeTable($tableName);
                    
                    if (!empty($issues) && $this->fixTableIssues($tableName, $issues)) {
                        $fixedCount++;
                        $this->log("✅ Emergency fixed: {$tableName}");
                    }
                } catch (Exception $e) {
                    $this->log("❌ Emergency fix failed for {$tableName}: " . $e->getMessage());
                }
            }
        }
        
        $this->log("🏁 Emergency recovery completed. Fixed {$fixedCount} critical tables.");
        return $fixedCount > 0;
    }
    
    // Add progress tracking for large operations
    private function processTablesInBatches($tables, $batchSize = 10) {
        $totalTables = count($tables);
        $batches = array_chunk($tables, $batchSize);
        $processedCount = 0;
        
        foreach ($batches as $batchIndex => $batch) {
            $this->log("\n📦 Processing batch " . ($batchIndex + 1) . "/" . count($batches) . " (" . count($batch) . " tables)");
            
            foreach ($batch as $tableName) {
                $processedCount++;
                $percentage = round(($processedCount / $totalTables) * 100, 1);
                
                $this->log("🔄 [{$percentage}%] Analyzing: {$tableName}");
                
                try {
                    $issues = $this->executeWithRetry(function() use ($tableName) {
                        return $this->analyzeTable($tableName);
                    });
                    
                    if (!empty($issues)) {
                        $this->log("⚠️  Issues found in {$tableName}: " . implode(', ', array_keys($issues)));
                        
                        $fixResult = $this->executeWithRetry(function() use ($tableName, $issues) {
                            return $this->fixTableIssues($tableName, $issues);
                        });
                        
                        if ($fixResult) {
                            $this->fixedTables[] = $tableName;
                            $this->log("✅ Fixed: {$tableName}");
                        }
                    }
                } catch (Exception $e) {
                    $this->log("❌ Error with {$tableName}: " . $e->getMessage());
                    $this->errors[] = "Table {$tableName}: " . $e->getMessage();
                }
                
                // Small delay between tables to prevent overwhelming
                usleep(200000); // 0.2 seconds
            }
            
            // Longer delay between batches
            if ($batchIndex < count($batches) - 1) {
                $this->log("⏸️  Batch completed. Resting for 2 seconds...");
                sleep(2);
            }
        }
    }
}

// =============================================================================
// SCRIPT EXECUTION
// =============================================================================

// Check if running from command line or web
$isCommandLine = php_sapi_name() === 'cli';

if (!$isCommandLine) {
    echo "<pre>";
}

try {
    // Configuration
    $config = [
        'host' => 'localhost',
                'username' => 'shahidmaladbusername',
                'password' => 'shahidmallapassword',
                'database' => 'shahidmalladb,
        'dry_run' => false,  // Set to true for testing
        'verbose' => true,
        'backup_enabled' => true,
        'log_enabled' => true,
        'connection_timeout' => 3600,
        'max_retries' => 5,
        'retry_delay' => 3,
        'memory_limit' => '1024M',
        'max_execution_time' => 0, // No limit
    ];
    
    // Handle command line arguments
    if ($isCommandLine && !empty($argv)) {
        foreach ($argv as $arg) {
            if ($arg === '--dry-run') $config['dry_run'] = true;
            if ($arg === '--quick-fix') $quickFix = true;
            if ($arg === '--emergency') $emergencyMode = true;
            if ($arg === '--silent') $config['verbose'] = false;
        }
    }
    
    // Initialize and run fixer
    $fixer = new WHMCSDBFixer($config);
    
    if (isset($emergencyMode)) {
        echo "🚨 EMERGENCY MODE ACTIVATED\n";
        $fixer->emergencyRecovery();
    } elseif (isset($quickFix)) {
        $fixer->quickFixCoreIssues();
    } else {
        $fixer->scanAndFixDatabase();
    }
    
} catch (Exception $e) {
    echo "💥 Fatal Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    
    // Try emergency recovery if main process fails
    if (!isset($emergencyMode)) {
        try {
            echo "\n🚨 Attempting emergency recovery...\n";
            $emergencyFixer = new WHMCSDBFixer([
                'host' => 'localhost',
                'username' => 'shahidmaladbusername',
                'password' => 'shahidmallapassword',
                'database' => 'shahidmalladb,
                'dry_run' => false,
                'verbose' => true,
                'max_retries' => 3,
            ]);
            $emergencyFixer->emergencyRecovery();
        } catch (Exception $emergencyError) {
            echo "❌ Emergency recovery also failed: " . $emergencyError->getMessage() . "\n";
        }
    }
}

if (!$isCommandLine) {
    echo "</pre>";
}

echo "\n🏁 Script execution completed. Check the log files for detailed information.\n";

?>
