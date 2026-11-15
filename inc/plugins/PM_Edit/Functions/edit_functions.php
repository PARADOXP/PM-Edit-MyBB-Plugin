<?php
/**
 * PM Edit Functions
 * Core editing functionality for PM Edit plugin
 */

if(!defined('IN_MYBB')) { die('Direct initialization of this file is not allowed.'); }

/**
 * Show the PM edit form
 */
function pm_edit_show_edit_form()
{
    global $mybb, $db, $lang, $templates, $theme, $header, $headerinclude, $footer;

    // Load language
    $lang->load("pm_edit");
    $lang->load("private");

    // Check if editing is enabled
    if($mybb->settings['pm_edit_enabled'] != 1)
    {
        error($lang->pm_edit_disabled);
    }

    // Get PM ID
    $pmid = $mybb->get_input('pmid', MyBB::INPUT_INT);

    if(!$pmid)
    {
        error($lang->pm_edit_invalid_pm);
    }

    // Get the PM
    $query = $db->query("
        SELECT pm.*, u.username, u.usergroup, u.displaygroup
        FROM ".TABLE_PREFIX."privatemessages pm
        LEFT JOIN ".TABLE_PREFIX."users u ON (u.uid = pm.fromid)
        WHERE pm.pmid = '{$pmid}' AND (pm.uid = '{$mybb->user['uid']}' OR pm.fromid = '{$mybb->user['uid']}')
    ");
    $pm = $db->fetch_array($query);

    if(!$pm)
    {
        error($lang->pm_edit_invalid_pm);
    }

    // Check permissions
    if(!pm_edit_can_edit_pm($pm))
    {
        error_no_permission();
    }

    // Check time limit
    if(!pm_edit_check_time_limit($pm))
    {
        error($lang->pm_edit_time_limit_exceeded);
    }

    // Setup variables for template
    $pmid = $pm['pmid'];
    $subject = htmlspecialchars_uni($pm['subject']);
    $message = htmlspecialchars_uni($pm['message']);
    $editreason = '';

    // Build code buttons (like private.php does)
    if($mybb->settings['bbcodeinserter'] != 0 && $mybb->user['showcodebuttons'] != 0)
    {
        $codebuttons = build_mycode_inserter("pmeditmessage", $mybb->settings['smilieinserter']);
    }
    else
    {
        $codebuttons = '';
    }

    if($mybb->settings['pm_edit_allow_edit_reason'] == 1)
    {
        $reason_field_template = $templates->get("pmedit_edit_reason_field");
        eval("\$editreason_field = \"{$reason_field_template}\";");
    }
    else
    {
        $editreason_field = '';
    }

    // Build navigation
    add_breadcrumb($lang->private_messaging, "private.php");
    add_breadcrumb($lang->pm_edit_title);

    $form_template = $templates->get("pmedit_edit_form");
    eval("\$page = \"{$form_template}\";");
    output_page($page);
}

/**
 * Process PM edit submission
 */
function pm_edit_process_edit()
{
    global $mybb, $db, $lang;

    // Load language
    $lang->load("pm_edit");

    // Verify POST request
    verify_post_check($mybb->get_input('my_post_key'));

    // Check if editing is enabled
    if($mybb->settings['pm_edit_enabled'] != 1)
    {
        error($lang->pm_edit_disabled);
    }

    // Get PM ID
    $pmid = $mybb->get_input('pmid', MyBB::INPUT_INT);

    if(!$pmid)
    {
        error($lang->pm_edit_invalid_pm);
    }

    // Get the PM
    $query = $db->query("
        SELECT pm.*, u.username, u.usergroup, u.displaygroup
        FROM ".TABLE_PREFIX."privatemessages pm
        LEFT JOIN ".TABLE_PREFIX."users u ON (u.uid = pm.fromid)
        WHERE pm.pmid = '{$pmid}' AND (pm.uid = '{$mybb->user['uid']}' OR pm.fromid = '{$mybb->user['uid']}')
    ");
    $pm = $db->fetch_array($query);

    if(!$pm)
    {
        error($lang->pm_edit_invalid_pm);
    }

    // Check permissions
    if(!pm_edit_can_edit_pm($pm))
    {
        error_no_permission();
    }

    // Check time limit
    if(!pm_edit_check_time_limit($pm))
    {
        error($lang->pm_edit_time_limit_exceeded);
    }

    // Get new values
    $subject = $mybb->get_input('subject', MyBB::INPUT_STRING);
    $message = $mybb->get_input('message', MyBB::INPUT_STRING);
    $editreason = '';

    if($mybb->settings['pm_edit_allow_edit_reason'] == 1)
    {
        $editreason = $mybb->get_input('editreason', MyBB::INPUT_STRING);
    }

    // Validate input
    if(my_strlen($subject) < 1)
    {
        error($lang->pm_edit_empty_subject);
    }

    if(my_strlen($message) < 1)
    {
        error($lang->pm_edit_empty_message);
    }

    // Track edit history if enabled
    if($mybb->settings['pm_edit_track_edits'] == 1)
    {
        pm_edit_save_history($pm, $subject, $message, $editreason);
    }

    // Update the PM
    $update_data = array(
        'subject' => $db->escape_string($subject),
        'message' => $db->escape_string($message),
        'edituid' => $mybb->user['uid'],
        'edittime' => TIME_NOW
    );

    if($mybb->settings['pm_edit_allow_edit_reason'] == 1)
    {
        $update_data['editreason'] = $db->escape_string($editreason);
    }

    $db->update_query("privatemessages", $update_data, "pmid = '{$pmid}'");

    // Hard edit: Also update recipient's copy if enabled and this is a sent PM
    if($mybb->settings['pm_edit_hard_edit_sent'] == 1 && $pm['folder'] == 2 && pm_edit_can_hard_edit())
    {
        // Get or generate conversation key
        $conversation_key = '';
        if(empty($pm['pm_conversation_key']))
        {
            // Generate new conversation key
            $conversation_key = md5(uniqid(rand(), true));
            
            // Update sender's copy with the new key
            $db->update_query("privatemessages", array('pm_conversation_key' => $conversation_key), "pmid = '{$pmid}'");
            
            // Also find and update recipient's copy (match by fromid, toid, and similar time)
            $time_window = 60;
            $db->update_query("privatemessages", 
                array('pm_conversation_key' => $conversation_key), 
                "fromid = '{$pm['fromid']}' AND toid = '{$pm['toid']}' AND uid != '{$pm['uid']}' AND dateline BETWEEN " . ($pm['dateline'] - $time_window) . " AND " . ($pm['dateline'] + $time_window)
            );
        }
        else
        {
            $conversation_key = $pm['pm_conversation_key'];
        }
        
        // Find recipient's copy by conversation key
        $recipient_copy = $db->fetch_array($db->simple_select(
            "privatemessages", 
            "pmid", 
            "pm_conversation_key = '{$conversation_key}' AND uid != '{$pm['uid']}'"
        ));
        
        if($recipient_copy)
        {
            $db->update_query("privatemessages", $update_data, "pmid = '{$recipient_copy['pmid']}'");
        }
    }

    // Update edit history count
    if($mybb->settings['pm_edit_track_edits'] == 1 && $mybb->settings['pm_edit_max_edit_history'] > 0)
    {
        pm_edit_cleanup_history($pmid);
    }

    // Redirect back to PM
    redirect("private.php?action=read&pmid={$pmid}", $lang->pm_edit_success);
}

/**
 * Show edit history for a PM
 */
function pm_edit_show_history()
{
    global $mybb, $db, $lang, $templates, $theme, $header, $headerinclude, $footer;

    // Load language
    $lang->load("pm_edit");
    $lang->load("private");

    // Get PM ID
    $pmid = $mybb->get_input('pmid', MyBB::INPUT_INT);

    if(!$pmid)
    {
        error($lang->pm_edit_invalid_pm);
    }

    // Get the PM
    $query = $db->query("
        SELECT pm.*, u.username, u.usergroup, u.displaygroup
        FROM ".TABLE_PREFIX."privatemessages pm
        LEFT JOIN ".TABLE_PREFIX."users u ON (u.uid = pm.fromid)
        WHERE pm.pmid = '{$pmid}' AND (pm.uid = '{$mybb->user['uid']}' OR pm.fromid = '{$mybb->user['uid']}')
    ");
    $pm = $db->fetch_array($query);

    if(!$pm)
    {
        error($lang->pm_edit_invalid_pm);
    }

    // Check permissions
    if(!pm_edit_can_edit_pm($pm))
    {
        error_no_permission();
    }

    // Build navigation
    add_breadcrumb($lang->private_messaging, "private.php");
    add_breadcrumb($lang->pm_edit_history);

    // Get edit history
    $query = $db->query("
        SELECT h.*, u.username, u.usergroup, u.displaygroup
        FROM ".TABLE_PREFIX."pm_edit_history h
        LEFT JOIN ".TABLE_PREFIX."users u ON (u.uid = h.edituid)
        WHERE h.pmid = '{$pmid}'
        ORDER BY h.dateline DESC
    ");

    $history_items = '';
    $history_count = 0;

    while($history = $db->fetch_array($query))
    {
        $history_count++;

        $edited_by = format_name($history['username'], $history['usergroup'], $history['displaygroup']);
        $edited_date = my_date('relative', $history['dateline']);
        
        // Use sprintf to replace {1} and {2} in language string
        $edited_by_text = $lang->sprintf($lang->pm_edit_edited_by, $edited_by, $edited_date);
        
        $original_subject = htmlspecialchars_uni($history['original_subject']);
        $edited_subject = htmlspecialchars_uni($history['edited_subject']);
        $edit_reason = htmlspecialchars_uni($history['editreason']);

        // Parse message for display
        $original_message = nl2br(htmlspecialchars_uni($history['original_message']));
        $edited_message = nl2br(htmlspecialchars_uni($history['edited_message']));

        // Highlight only changed parts in subject
        if($original_subject != $edited_subject)
        {
            $original_subject = '<span class="pm_edit_old">'.$original_subject.'</span>';
            $edited_subject = '<span class="pm_edit_new">'.$edited_subject.'</span>';
        }
        
        // Use word diff for message comparison
        if($original_message != $edited_message)
        {
            $diff_result = pm_edit_diff_highlight($history['original_message'], $history['edited_message']);
            // The diff function returns: <div class="pm_edit_old">OLD</div><!--SPLIT--><div class="pm_edit_new">NEW</div>
            // Split using the marker
            $marker = '<!--SPLIT-->';
            $split_pos = strpos($diff_result, $marker);
            if($split_pos !== false)
            {
                $original_message = substr($diff_result, 0, $split_pos);
                $edited_message = substr($diff_result, $split_pos + strlen($marker));
            }
            else
            {
                $original_message = '<div class="pm_edit_old">' . $original_message . '</div>';
                $edited_message = '<div class="pm_edit_new">' . $edited_message . '</div>';
            }
        }
        else
        {
            $original_message = '<div class="pm_edit_old">' . $original_message . '</div>';
            $edited_message = '<div class="pm_edit_new">' . $edited_message . '</div>';
        }

        if(empty($edit_reason))
        {
            $edit_reason = $lang->pm_edit_no_reason;
        }

        $history_item_template = $templates->get("pmedit_edit_history_item");
        eval("\$history_items .= \"{$history_item_template}\";");
    }

    if($history_count == 0)
    {
        $history_empty_template = $templates->get("pmedit_edit_history_empty");
        eval("\$history_items = \"{$history_empty_template}\";");
    }

    $history_page_template = $templates->get("pmedit_edit_history");
    eval("\$page = \"{$history_page_template}\";");
    output_page($page);
}

/**
 * Save edit history
 */
function pm_edit_save_history($pm, $new_subject, $new_message, $editreason)
{
    global $mybb, $db;

    // Insert history record
    $history_data = array(
        'pmid' => $pm['pmid'],
        'uid' => $pm['uid'],
        'edituid' => $mybb->user['uid'],
        'original_subject' => $db->escape_string($pm['subject']),
        'original_message' => $db->escape_string($pm['message']),
        'edited_subject' => $db->escape_string($new_subject),
        'edited_message' => $db->escape_string($new_message),
        'editreason' => $db->escape_string($editreason),
        'dateline' => TIME_NOW,
        'ipaddress' => $db->escape_string(get_ip())
    );

    $db->insert_query("pm_edit_history", $history_data);
}

/**
 * Clean up old history entries if max limit is set
 */
function pm_edit_cleanup_history($pmid)
{
    global $mybb, $db;

    $max_entries = (int)$mybb->settings['pm_edit_max_edit_history'];

    if($max_entries <= 0)
    {
        return;
    }

    // Count current entries
    $query = $db->simple_select("pm_edit_history", "COUNT(*) as count", "pmid = '{$pmid}'");
    $count = $db->fetch_field($query, "count");

    if($count > $max_entries)
    {
        // Delete oldest entries
        $delete_count = $count - $max_entries;
        $query = $db->query("
            SELECT hid FROM ".TABLE_PREFIX."pm_edit_history
            WHERE pmid = '{$pmid}'
            ORDER BY dateline ASC
            LIMIT {$delete_count}
        ");

        $hids = array();
        while($row = $db->fetch_array($query))
        {
            $hids[] = $row['hid'];
        }

        if(!empty($hids))
        {
            $hid_list = implode(',', $hids);
            $db->delete_query("pm_edit_history", "hid IN ({$hid_list})");
        }
    }
}

/**
 * Check if PM has edit history
 */
function pm_edit_has_history($pmid)
{
    global $mybb, $db;

    if($mybb->settings['pm_edit_track_edits'] != 1)
    {
        return false;
    }

    $query = $db->simple_select("pm_edit_history", "COUNT(*) as count", "pmid = '{$pmid}'");
    return $db->fetch_field($query, "count") > 0;
}

/**
 * Get edit notice for a PM
 */
function pm_edit_get_notice($pm)
{
    global $mybb, $db, $lang;

    if($mybb->settings['pm_edit_show_edit_notice'] != 1)
    {
        return '';
    }

    if(empty($pm['edittime']) || empty($pm['edituid']))
    {
        return '';
    }

    // Get editor info
    $query = $db->simple_select("users", "username, usergroup, displaygroup", "uid = '{$pm['edituid']}'");
    $editor = $db->fetch_array($query);

    if(!$editor)
    {
        return '';
    }

    $editor_name = format_name($editor['username'], $editor['usergroup'], $editor['displaygroup']);
    $edit_date = my_date('relative', $pm['edittime']);

    return $lang->sprintf($lang->pm_edited_by, $editor_name, $edit_date);
}

/**
 * Update PM (for quick edit)
 * Returns array with success status and message
 */
function pm_edit_update_pm($pmid, $message, $editreason = '')
{
    global $mybb, $db, $lang, $parser;

    $lang->load("pm_edit");

    // Validate input
    if(my_strlen($message) < 1)
    {
        return array(
            'success' => false,
            'errors' => $lang->pm_edit_empty_message
        );
    }

    // Get the PM
    $pm = $db->fetch_array($db->simple_select("privatemessages", "*", "pmid = '{$pmid}'"));

    if(!$pm)
    {
        return array(
            'success' => false,
            'errors' => $lang->pm_edit_invalid_pm
        );
    }

    // Check permissions
    if(!pm_edit_can_edit_pm($pm))
    {
        return array(
            'success' => false,
            'errors' => $lang->no_permission
        );
    }

    // Check time limit
    if(!pm_edit_check_time_limit($pm))
    {
        return array(
            'success' => false,
            'errors' => $lang->pm_edit_time_limit_exceeded
        );
    }

    // Track edit history if enabled
    if($mybb->settings['pm_edit_track_edits'] == 1)
    {
        pm_edit_save_history($pm, $pm['subject'], $message, $editreason);
    }

    // Update the PM
    $update_data = array(
        'message' => $db->escape_string($message),
        'edituid' => $mybb->user['uid'],
        'edittime' => TIME_NOW
    );

    if($mybb->settings['pm_edit_allow_edit_reason'] == 1)
    {
        $update_data['editreason'] = $db->escape_string($editreason);
    }

    $db->update_query("privatemessages", $update_data, "pmid = '{$pmid}'");

    // Hard edit: Also update recipient's copy if enabled and this is a sent PM
    if($mybb->settings['pm_edit_hard_edit_sent'] == 1 && $pm['folder'] == 2 && pm_edit_can_hard_edit())
    {
        // Get or generate conversation key
        $conversation_key = '';
        if(empty($pm['pm_conversation_key']))
        {
            // Generate new conversation key
            $conversation_key = md5(uniqid(rand(), true));
            
            // Update sender's copy with the new key
            $db->update_query("privatemessages", array('pm_conversation_key' => $conversation_key), "pmid = '{$pmid}'");
            
            // Also find and update recipient's copy
            $time_window = 60;
            $db->update_query("privatemessages", 
                array('pm_conversation_key' => $conversation_key), 
                "fromid = '{$pm['fromid']}' AND toid = '{$pm['toid']}' AND uid != '{$pm['uid']}' AND dateline BETWEEN " . ($pm['dateline'] - $time_window) . " AND " . ($pm['dateline'] + $time_window)
            );
        }
        else
        {
            $conversation_key = $pm['pm_conversation_key'];
        }
        
        // Find recipient's copy by conversation key
        $recipient_copy = $db->fetch_array($db->simple_select(
            "privatemessages", 
            "pmid", 
            "pm_conversation_key = '{$conversation_key}' AND uid != '{$pm['uid']}'"
        ));
        
        if($recipient_copy)
        {
            $db->update_query("privatemessages", $update_data, "pmid = '{$recipient_copy['pmid']}'");
        }
    }

    // Update edit history count
    if($mybb->settings['pm_edit_track_edits'] == 1 && $mybb->settings['pm_edit_max_edit_history'] > 0)
    {
        pm_edit_cleanup_history($pmid);
    }

    // Build edited message for response
    // Parse the message to HTML (BBCode/MyCode to HTML)
    require_once MYBB_ROOT . "inc/class_parser.php";
    $parser = new postParser;

    $parser_options = array(
        'allow_html' => 0,
        'allow_mycode' => 1,
        'allow_smilies' => 1,
        'allow_imgcode' => 1,
        'filter_badwords' => 1
    );
    $parsed_message = $parser->parse_message($message, $parser_options);

    $edit_date = my_date('relative', TIME_NOW);
    $edit_username = format_name($mybb->user['username'], $mybb->user['usergroup'], $mybb->user['displaygroup']);
    $editedmsg = $lang->sprintf($lang->pm_edited_by, $edit_username, $edit_date);

    // Generate edit history link HTML for AJAX response
    $history_link = '';
    if($mybb->settings['pm_edit_track_edits'] == 1)
    {
        require_once MYBB_ROOT . "inc/plugins/PM_Edit/Functions/permission_functions.php";
        // Check if history exists after this edit
        if(pm_edit_has_history($pmid))
        {
            $history_url = $mybb->settings['bburl'] . '/private.php?action=pm_edit_history&pmid=' . $pmid;
            $history_link = '<a href="' . $history_url . '" id="pm_edit_history_' . $pmid . '" class="pm_edit_history_link" title="' . $lang->pm_edit_history . '"><span>' . $lang->pm_edit_history . '</span></a>';
        }
    }

    return array(
        'success' => true,
        'message' => $parsed_message,
        'editedmsg' => $editedmsg,
        'historylink' => $history_link
    );
}

/**
 * Highlight differences between two strings - Show only changed portions
 * @param string $old The old string (raw)
 * @param string $new The new string (raw)
 * @return string HTML with original and edited highlighted
 */
function pm_edit_diff_highlight($old, $new)
{
    // Escape for display
    $old_html = nl2br(htmlspecialchars_uni($old));
    $new_html = nl2br(htmlspecialchars_uni($new));
    
    // Check similarity
    $similarity = 0;
    if(strlen($old) > 0 && strlen($new) > 0) {
        similar_text($old, $new, $similarity);
    }
    
    // If very different (>70% changed), just show full content
    if($similarity < 30) {
        return '<div class="pm_edit_old">' . $old_html . '</div><!--SPLIT--><div class="pm_edit_new">' . $new_html . '</div>';
    }
    
    // Use changed-only diff
    return pm_edit_changed_only_diff($old, $new);
}

/**
 * Show only changed portions with context
 */
function pm_edit_changed_only_diff($old, $new)
{
    // Split into words preserving whitespace
    $old_words = pm_edit_split_words($old);
    $new_words = pm_edit_split_words($new);
    
    // Get diff with positions
    $diff_result = pm_edit_compute_diff_with_positions($old_words, $new_words);
    
    // Build HTML - only changed sections with context
    $old_html = pm_edit_build_changed_only_html($diff_result['old'], 'old');
    $new_html = pm_edit_build_changed_only_html($diff_result['new'], 'new');
    
    if(empty($old_html) && empty($new_html)) {
        // Fallback to full display
        return '<div class="pm_edit_old">' . nl2br(htmlspecialchars_uni($old)) . '</div><!--SPLIT--><div class="pm_edit_new">' . nl2br(htmlspecialchars_uni($new)) . '</div>';
    }
    
    return '<div class="pm_edit_old">' . $old_html . '</div><!--SPLIT--><div class="pm_edit_new">' . $new_html . '</div>';
}

/**
 * Compute diff with change positions
 */
function pm_edit_compute_diff_with_positions($old_words, $new_words)
{
    $old_count = count($old_words);
    $new_count = count($new_words);
    
    // Build LCS table
    $lcs = array();
    for($i = 0; $i <= $old_count; $i++) {
        $lcs[$i] = array();
        for($j = 0; $j <= $new_count; $j++) {
            $lcs[$i][$j] = 0;
        }
    }
    
    for($i = 1; $i <= $old_count; $i++) {
        for($j = 1; $j <= $new_count; $j++) {
            if($old_words[$i-1] === $new_words[$j-1]) {
                $lcs[$i][$j] = $lcs[$i-1][$j-1] + 1;
            } else {
                $lcs[$i][$j] = max($lcs[$i-1][$j], $lcs[$i][$j-1]);
            }
        }
    }
    
    // Backtrack to find diff with positions
    $old_result = array();
    $new_result = array();
    $i = $old_count;
    $j = $new_count;
    
    while($i > 0 || $j > 0) {
        if($i > 0 && $j > 0 && $old_words[$i-1] === $new_words[$j-1]) {
            // Common word
            $old_result[] = array('type' => 'same', 'word' => $old_words[$i-1]);
            $new_result[] = array('type' => 'same', 'word' => $new_words[$j-1]);
            $i--;
            $j--;
        } elseif($j > 0 && ($i == 0 || $lcs[$i][$j-1] >= $lcs[$i-1][$j])) {
            // Added word
            $new_result[] = array('type' => 'add', 'word' => $new_words[$j-1]);
            $j--;
        } elseif($i > 0) {
            // Removed word
            $old_result[] = array('type' => 'remove', 'word' => $old_words[$i-1]);
            $i--;
        }
    }
    
    // Reverse to get correct order
    return array(
        'old' => array_reverse($old_result),
        'new' => array_reverse($new_result)
    );
}

/**
 * Build HTML showing only changed sections with context
 */
function pm_edit_build_changed_only_html($diff_array, $type)
{
    if(empty($diff_array)) {
        return '';
    }
    
    $html = '';
    $total_words = count($diff_array);
    $context_words = 3; // Words of context before/after change
    
    // Find all change regions
    $change_regions = array();
    $in_change = false;
    $change_start = 0;
    
    for($idx = 0; $idx < $total_words; $idx++) {
        $word_type = $diff_array[$idx]['type'];
        
        if($word_type !== 'same') {
            if(!$in_change) {
                $change_start = max(0, $idx - $context_words);
                $in_change = true;
            }
        } else {
            if($in_change) {
                // End of change region
                $change_end = min($total_words, $idx + $context_words);
                $change_regions[] = array('start' => $change_start, 'end' => $change_end);
                $in_change = false;
            }
        }
    }
    
    // Last region
    if($in_change) {
        $change_end = min($total_words, $total_words);
        $change_regions[] = array('start' => $change_start, 'end' => $change_end);
    }
    
    // If no changes found, return empty
    if(empty($change_regions)) {
        return '';
    }
    
    // Build HTML from change regions
    $last_end = 0;
    $region_count = count($change_regions);
    
    for($r = 0; $r < $region_count; $r++) {
        $region = $change_regions[$r];
        
        // Add separator between regions
        if($r > 0 && $region['start'] > $last_end + $context_words * 2) {
            $html .= '<span class="pm_edit_diff_ellipsis">... </span>';
        }
        
        // Build this region
        for($idx = $region['start']; $idx < $region['end']; $idx++) {
            if($idx >= $total_words) break;
            
            $item = $diff_array[$idx];
            $word = htmlspecialchars_uni($item['word']);
            
            if($item['type'] === 'same') {
                $html .= '<span>'.$word.'</span>';
            } elseif($item['type'] === 'add') {
                $html .= '<span class="pm_edit_new">'.$word.'</span>';
            } else {
                $html .= '<span class="pm_edit_old">'.$word.'</span>';
            }
        }
        
        $last_end = $region['end'];
    }
    
    return $html;
}

/**
 * Word-level diff for side-by-side display
 */
function pm_edit_word_diff($old, $new)
{
    // Split into words preserving whitespace
    $old_words = pm_edit_split_words($old);
    $new_words = pm_edit_split_words($new);
    
    // Compute LCS-based diff
    $diff_result = pm_edit_compute_diff($old_words, $new_words);
    
    return $diff_result;
}

/**
 * Split string into words while preserving whitespace
 */
function pm_edit_split_words($string)
{
    $words = array();
    $length = strlen($string);
    $pos = 0;
    
    while($pos < $length) {
        // Eat whitespace
        $spaces = strspn(substr($string, $pos), " \n\t\r");
        if($spaces > 0) {
            $words[] = substr($string, $pos, $spaces);
            $pos += $spaces;
        }
        
        if($pos >= $length) break;
        
        // Eat non-whitespace (word)
        $nextpos = strcspn(substr($string, $pos), " \n\t\r");
        if($nextpos > 0) {
            $words[] = substr($string, $pos, $nextpos);
            $pos += $nextpos;
        }
    }
    
    return $words;
}

/**
 * Compute diff using LCS algorithm
 */
function pm_edit_compute_diff($old_words, $new_words)
{
    $old_count = count($old_words);
    $new_count = count($new_words);
    
    // Build LCS table
    $lcs = array();
    for($i = 0; $i <= $old_count; $i++) {
        $lcs[$i] = array();
        for($j = 0; $j <= $new_count; $j++) {
            $lcs[$i][$j] = 0;
        }
    }
    
    for($i = 1; $i <= $old_count; $i++) {
        for($j = 1; $j <= $new_count; $j++) {
            if($old_words[$i-1] === $new_words[$j-1]) {
                $lcs[$i][$j] = $lcs[$i-1][$j-1] + 1;
            } else {
                $lcs[$i][$j] = max($lcs[$i-1][$j], $lcs[$i][$j-1]);
            }
        }
    }
    
    // Backtrack to build diff
    $old_result = '';
    $new_result = '';
    $i = $old_count;
    $j = $new_count;
    
    while($i > 0 || $j > 0) {
        if($i > 0 && $j > 0 && $old_words[$i-1] === $new_words[$j-1]) {
            // Common word
            $old_result = '<span>'.htmlspecialchars_uni($old_words[$i-1]).'</span>'.$old_result;
            $new_result = '<span>'.htmlspecialchars_uni($new_words[$j-1]).'</span>'.$new_result;
            $i--;
            $j--;
        } elseif($j > 0 && ($i == 0 || $lcs[$i][$j-1] >= $lcs[$i-1][$j])) {
            // Added word
            $new_result = '<span class="pm_edit_new">'.htmlspecialchars_uni($new_words[$j-1]).'</span>'.$new_result;
            $j--;
        } elseif($i > 0) {
            // Removed word
            $old_result = '<span class="pm_edit_old">'.htmlspecialchars_uni($old_words[$i-1]).'</span>'.$old_result;
            $i--;
        }
    }
    
    // Convert newlines to <br> for display
    $old_result = nl2br($old_result);
    $new_result = nl2br($new_result);
    
    // Return with proper closing divs
    return '<div class="pm_edit_old">'.$old_result.'</div><!--SPLIT--><div class="pm_edit_new">'.$new_result.'</div>';
}

/**
 * Check if user can hard edit (edit recipient's copy)
 */
function pm_edit_can_hard_edit()
{
    global $mybb;

    // Admins always can hard edit
    if(!empty($mybb->usergroup['cancp']))
    {
        return true;
    }

    $allowed_groups = trim($mybb->settings['pm_edit_hard_edit_sent_groups']);

    // If empty, use the general allowed groups
    if(empty($allowed_groups))
    {
        return pm_edit_check_usergroup();
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
