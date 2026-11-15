<?php
/**
 * PM Edit Plugin - Task
 * Prunes edit logs older than 7 days
 */

function task_pm_edit_prune_logs($task)
{
    global $db;

    // Get the pruning days setting (default 7 days)
    $prune_days = 7;
    
    // Calculate cutoff time
    $cutoff = TIME_NOW - ($prune_days * 86400);

    // Delete old logs
    $deleted = $db->delete_query('pm_edit_history', "dateline < '{$cutoff}'");

    // Log the task
    if($deleted > 0)
    {
        $task->log("PM Edit Logs: Deleted {$deleted} old edit log entries.");
    }
}
