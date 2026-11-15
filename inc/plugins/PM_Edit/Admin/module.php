<?php
/**
 * PM Edit Plugin - Admin Module
 * PM Edit Logs functionality
 */

if(!defined('IN_MYBB'))
{
    die('Direct initialization of this file is not allowed.');
}

function pm_edit_admin_menu(&$sub_menu)
{
    $sub_menu[] = array(
        'id' => 'pm_edit_logs',
        'title' => 'PM Edit Logs',
        'link' => 'index.php?module=tools-pm_edit_logs'
    );
}

function pm_edit_admin_action_handler(&$actions)
{
    $actions['pm_edit_logs'] = array(
        'active' => 'pm_edit_logs',
        'file' => ''
    );
}

function pm_edit_admin_load()
{
    global $mybb, $db, $page, $lang;

    if($mybb->input['module'] == 'tools-pm_edit_logs')
    {
        // Load language
        $lang->load('pm_edit');

        $page->add_breadcrumb_item('PM Edit Logs', 'index.php?module=tools-pm_edit_logs');

        if($mybb->request_method == 'post')
        {
            if($mybb->input['do'] == 'delete')
            {
                $hid = (int)$mybb->input['hid'];
                if($hid > 0)
                {
                    $db->delete_query('pm_edit_history', "hid = '{$hid}'");
                }
            }
            elseif($mybb->input['do'] == 'bulk_delete')
            {
                $logs = $mybb->input['logs'];
                if(is_array($logs) && !empty($logs))
                {
                    $ids = array_map('intval', $logs);
                    $ids = implode(',', $ids);
                    $db->delete_query('pm_edit_history', "hid IN ({$ids})");
                    flash_message('Selected logs deleted successfully.', 'success');
                    admin_redirect('index.php?module=tools-pm_edit_logs');
                }
            }
            elseif($mybb->input['do'] == 'delete_all')
            {
                $db->delete_query('pm_edit_history');
            }
            
            flash_message('Edit logs deleted successfully.', 'success');
            admin_redirect('index.php?module=tools-pm_edit_logs');
        }

        // Handle view action
        if($mybb->input['action'] == 'view')
        {
            pm_edit_admin_view_log();
            exit;
        }

        pm_edit_admin_show_logs();
        exit;
    }
}

function pm_edit_admin_show_logs()
{
    global $mybb, $db, $page, $lang;

    $page->output_header('PM Edit Logs');

    $table = new Table;
    $table->construct_header('<input type="checkbox" id="check_all" onclick="var cbs = document.getElementsByClassName(\'log_checkbox\'); for(var i=0; i<cbs.length; i++) cbs[i].checked = this.checked;" />', array('width' => '40'));
    $table->construct_header($lang->pm_edit_log_date, array('width' => '180'));
    $table->construct_header($lang->pm_edit_log_editor, array('width' => '150'));
    $table->construct_header($lang->pm_edit_log_pm_id, array('width' => '80'));
    $table->construct_header($lang->pm_edit_log_subject, array('width' => '200'));
    $table->construct_header($lang->pm_edit_log_edit_reason, array('width' => '150'));
    $table->construct_header($lang->pm_edit_log_actions, array('width' => '80', 'align' => 'center'));

    // Build query
    $query = $db->query("
        SELECT h.*, u.username as editor_username, p.subject as pm_subject
        FROM ".TABLE_PREFIX."pm_edit_history h
        LEFT JOIN ".TABLE_PREFIX."users u ON (h.edituid = u.uid)
        LEFT JOIN ".TABLE_PREFIX."privatemessages p ON (h.pmid = p.pmid)
        ORDER BY h.dateline DESC
        LIMIT 100
    ");

    $has_results = false;
    while($row = $db->fetch_array($query))
    {
        $has_results = true;
        $table->construct_cell('<input type="checkbox" name="logs[]" value="'.$row['hid'].'" class="log_checkbox" />');
        $table->construct_cell(my_date('relative', $row['dateline']));
        
        $editor_link = build_profile_link(htmlspecialchars_uni($row['editor_username']), $row['edituid']);
        $table->construct_cell($editor_link);
        
        $table->construct_cell($row['pmid']);
        
        $subject = htmlspecialchars_uni($row['pm_subject']);
        if(my_strlen($subject) > 30)
        {
            $subject = my_substr($subject, 0, 30) . '...';
        }
        $table->construct_cell($subject);
        
        $editreason = htmlspecialchars_uni($row['editreason']);
        if(empty($editreason))
        {
            $editreason = '-';
        }
        elseif(my_strlen($editreason) > 20)
        {
            $editreason = my_substr($editreason, 0, 20) . '...';
        }
        $table->construct_cell($editreason);
        
        $table->construct_cell("<a href=\"index.php?module=tools-pm_edit_logs&action=view&hid={$row['hid']}\">{$lang->pm_edit_log_view}</a> | <a href=\"index.php?module=tools-pm_edit_logs&do=delete&hid={$row['hid']}\" onclick=\"return confirm('{$lang->pm_edit_log_delete_confirm}');\">{$lang->pm_edit_log_delete}</a>", array('align' => 'center'));
        
        $table->construct_row();
    }

    if(!$has_results)
    {
        $table->construct_cell($lang->pm_edit_log_no_results, array('colspan' => 7, 'class' => 'align_center'));
        $table->construct_row();
    }

    $table->output('PM Edit Logs');

    // Add buttons - centered
    echo '<br /><div style="text-align: center;">
    <form method="post" action="index.php?module=tools-pm_edit_logs" style="display: inline;" id="bulk_delete_form">
        <input type="hidden" name="do" value="bulk_delete" />
        <input type="submit" class="button" value="Delete Selected" onclick="var checked = document.querySelectorAll(\'.log_checkbox:checked\'); if(checked.length == 0) { alert(\'Please select at least one log to delete.\'); return false; } return confirm(\'Are you sure you want to delete selected logs?\');" />
    </form>
    &nbsp;
    <form method="post" action="index.php?module=tools-pm_edit_logs" style="display: inline;">
        <input type="hidden" name="do" value="delete_all" />
        <input type="submit" class="button" value="'.$lang->pm_edit_log_delete_all.'" onclick="return confirm(\''.$lang->pm_edit_log_delete_all_confirm.'\');" />
    </form>
    </div>';

    $page->output_footer();
}

function pm_edit_admin_view_log()
{
    global $mybb, $db, $page, $lang;

    $hid = (int)$mybb->input['hid'];
    
    $query = $db->query("
        SELECT h.*, u.username as editor_username, p.subject as pm_subject
        FROM ".TABLE_PREFIX."pm_edit_history h
        LEFT JOIN ".TABLE_PREFIX."users u ON (h.edituid = u.uid)
        LEFT JOIN ".TABLE_PREFIX."privatemessages p ON (h.pmid = p.pmid)
        WHERE h.hid = '{$hid}'
    ");
    $row = $db->fetch_array($query);
    
    if($row)
    {
        $page->output_header('View PM Edit - ' . htmlspecialchars_uni($row['pm_subject']));
        
        $form = new Form('index.php?module=tools-pm_edit_logs', 'post');
        
        $form_container = new FormContainer($lang->pm_edit_log_details);
        
        $form_container->output_row($lang->pm_edit_log_date, my_date('F j, Y, g:i a', $row['dateline']));
        $form_container->output_row($lang->pm_edit_log_editor, build_profile_link(htmlspecialchars_uni($row['editor_username']), $row['edituid']));
        $form_container->output_row($lang->pm_edit_log_pm_id, $row['pmid']);
        $form_container->output_row($lang->pm_edit_log_subject, htmlspecialchars_uni($row['pm_subject']));
        $form_container->output_row($lang->pm_edit_log_edit_reason, htmlspecialchars_uni($row['editreason']));
        
        $form_container->output_row($lang->pm_edit_log_original_subject, htmlspecialchars_uni($row['original_subject']));
        $form_container->output_row($lang->pm_edit_log_original_message, htmlspecialchars_uni($row['original_message']));
        
        $form_container->output_row($lang->pm_edit_log_edited_subject, htmlspecialchars_uni($row['edited_subject']));
        $form_container->output_row($lang->pm_edit_log_edited_message, htmlspecialchars_uni($row['edited_message']));
        
        $form_container->output_row($lang->pm_edit_log_ip_address, $row['ipaddress']);
        
        $form_container->end();
        
        $buttons = [];
        $buttons[] = $form->generate_submit_button($lang->pm_edit_log_back, array('onclick' => "window.location='index.php?module=tools-pm_edit_logs'; return false;"));
        
        $form->output_submit_wrapper($buttons);
        $form->end();
        
        $page->output_footer();
    }
    else
    {
        admin_redirect('index.php?module=tools-pm_edit_logs');
    }
}
