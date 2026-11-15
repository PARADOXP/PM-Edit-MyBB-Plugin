<?php
/**
 * PM Edit Permission Functions
 * Handles permission checks for PM editing
 */

if(!defined('IN_MYBB')) { die('Direct initialization of this file is not allowed.'); }

/**
 * Check if user can edit a PM
 *
 * @param array $pm The PM data array
 * @return bool True if can edit, false otherwise
 */
function pm_edit_can_edit_pm($pm)
{
    global $mybb, $db;

    // Check if editing is enabled
    if($mybb->settings['pm_edit_enabled'] != 1)
    {
        return false;
    }

    // Check if user is logged in
    if($mybb->user['uid'] == 0)
    {
        return false;
    }

    // Admins and super mods can always edit - check FIRST
    if(!empty($mybb->usergroup['cancp']) || !empty($mybb->usergroup['issupermod']))
    {
        return true;
    }

    // Check usergroup permissions
    if(!pm_edit_check_usergroup())
    {
        return false;
    }

    // Get excluded folders
    $excluded_folders = pm_edit_get_excluded_folders();
    if(in_array($pm['folder'], $excluded_folders))
    {
        return false;
    }

    // Check if user is sender or recipient
    $is_sender = ($pm['fromid'] == $mybb->user['uid']);
    $is_recipient = false;
    
    // Check multiple fields to determine if user is recipient
    if(isset($pm['toid']) && $pm['toid'] == $mybb->user['uid']) {
        $is_recipient = true;
    } elseif(isset($pm['uid']) && $pm['uid'] == $mybb->user['uid'] && $pm['fromid'] != $mybb->user['uid']) {
        $is_recipient = true;
    }

    // Check edit permissions based on sender/recipient
    if($is_sender && isset($mybb->settings['pm_edit_allow_edit_sent']) && $mybb->settings['pm_edit_allow_edit_sent'] != 1)
    {
        return false;
    }

    if($is_recipient && isset($mybb->settings['pm_edit_allow_edit_received']) && $mybb->settings['pm_edit_allow_edit_received'] != 1)
    {
        return false;
    }

    // Must be either sender or recipient
    if(!$is_sender && !$is_recipient)
    {
        return false;
    }

    // Moderators can always edit in their moderated forums
    if(!empty($mybb->usergroup['canmodcp']))
    {
        return true;
    }

    return true;
}

/**
 * Check if user's group has permission to edit PMs
 *
 * @return bool True if allowed, false otherwise
 */
function pm_edit_check_usergroup()
{
    global $mybb;

    $allowed_groups = trim($mybb->settings['pm_edit_allowed_groups']);

    // If empty, allow all registered users
    if(empty($allowed_groups))
    {
        return $mybb->user['uid'] > 0;
    }

    // Parse allowed groups
    $allowed = array_map('trim', explode(',', $allowed_groups));
    $allowed = array_map('intval', $allowed);

    // Check if user's primary or additional groups are allowed
    $user_groups = array($mybb->user['usergroup']);
    if(!empty($mybb->user['additionalgroups']))
    {
        $additional = explode(',', $mybb->user['additionalgroups']);
        $user_groups = array_merge($user_groups, $additional);
    }

    // Check for intersection
    foreach($user_groups as $gid)
    {
        if(in_array(intval($gid), $allowed))
        {
            return true;
        }
    }

    return false;
}

/**
 * Check time limit for editing
 *
 * @param array $pm The PM data array
 * @return bool True if within time limit, false otherwise
 */
function pm_edit_check_time_limit($pm)
{
    global $mybb;

    // Admins and super mods bypass time limit
    if($mybb->usergroup['cancp'] == 1 || $mybb->usergroup['issupermod'] == 1)
    {
        return true;
    }

    // Get time limit (in minutes)
    $time_limit = (int)$mybb->settings['pm_edit_edittimelimit'];

    // 0 means unlimited
    if($time_limit == 0)
    {
        return true;
    }

    // Calculate cutoff time
    $cutoff = TIME_NOW - ($time_limit * 60);

    // Check if PM was created before cutoff
    // Use dateline of PM for received PMs, or check if sender's copy
    if($pm['dateline'] < $cutoff)
    {
        return false;
    }

    return true;
}

/**
 * Get array of excluded folder IDs
 *
 * @return array Array of folder IDs where editing is not allowed
 */
function pm_edit_get_excluded_folders()
{
    global $mybb;

    $excluded = trim($mybb->settings['pm_edit_exclude_folders']);

    if(empty($excluded))
    {
        return array();
    }

    // Parse folder IDs
    $folders = array_map('trim', explode(',', $excluded));
    $folders = array_map('intval', $folders);

    // Return unique values
    return array_unique($folders);
}

/**
 * Check if user can view edit history for a PM
 *
 * @param array $pm The PM data array
 * @return bool True if can view, false otherwise
 */
function pm_edit_can_view_history($pm)
{
    global $mybb;

    // Check if history tracking is enabled
    if($mybb->settings['pm_edit_track_edits'] != 1)
    {
        return false;
    }

    // Must be sender or recipient
    $is_sender = ($pm['fromid'] == $mybb->user['uid']);
    $is_recipient = ($pm['uid'] == $mybb->user['uid']);

    if(!$is_sender && !$is_recipient)
    {
        return false;
    }

    // Admins and super mods can always view
    if($mybb->usergroup['cancp'] == 1 || $mybb->usergroup['issupermod'] == 1)
    {
        return true;
    }

    // Sender and recipient can view their own PM history
    return true;
}
