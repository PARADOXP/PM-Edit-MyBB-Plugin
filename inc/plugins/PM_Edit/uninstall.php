<?php
if(!defined('IN_MYBB')) { die('Direct initialization of this file is not allowed.'); }

if (!defined("PLUGINLIBRARY")) {
    define("PLUGINLIBRARY", MYBB_ROOT . 'inc/plugins/pluginlibrary.php');
}

function pm_edit_uninstall_run()
{
    global $db;

    // Drop edit tracking columns from privatemessages table
    if($db->field_exists("edituid", "privatemessages"))
    {
        $db->write_query("ALTER TABLE ".$db->table_prefix."privatemessages DROP COLUMN edituid");
    }
    if($db->field_exists("edittime", "privatemessages"))
    {
        $db->write_query("ALTER TABLE ".$db->table_prefix."privatemessages DROP COLUMN edittime");
    }
    if($db->field_exists("editreason", "privatemessages"))
    {
        $db->write_query("ALTER TABLE ".$db->table_prefix."privatemessages DROP COLUMN editreason");
    }
    if($db->field_exists("pm_conversation_key", "privatemessages"))
    {
        $db->write_query("ALTER TABLE ".$db->table_prefix."privatemessages DROP COLUMN pm_conversation_key");
    }

    // Drop edit history table
    if($db->table_exists("pm_edit_history"))
    {
        $db->drop_table("pm_edit_history");
    }

    // Remove settings via PluginLibrary
    if(file_exists(PLUGINLIBRARY))
    {
        require_once PLUGINLIBRARY;
        $PL = new PluginLibrary();
        $PL->settings_delete("pm_edit");
    }

    // Remove stylesheet
    if(file_exists(PLUGINLIBRARY))
    {
        require_once PLUGINLIBRARY;
        $PL = new PluginLibrary();
        $PL->stylesheet_delete('pm_edit');
    }

    // Remove templates
    require_once MYBB_ROOT."inc/adminfunctions_templates.php";

    // Remove PM edit button and history link from postbit templates
    find_replace_templatesets("postbit", "#" . preg_quote('{$post[\'button_delete_pm\']}{$post[\'button_edit_pm\']}{$post[\'pm_edit_history\']}') . "#i", '{$post[\'button_delete_pm\']}');
    find_replace_templatesets("postbit_classic", "#" . preg_quote('{$post[\'button_delete_pm\']}{$post[\'button_edit_pm\']}{$post[\'pm_edit_history\']}') . "#i", '{$post[\'button_delete_pm\']}');

    // Remove edit notice from private_read template
    find_replace_templatesets("private_read", "#" . preg_quote('{$pm[\'edit_notice\']}') . "#i", '');

    // Delete templates from database
    pm_edit_remove_templates();

    // Remove template group
    $db->delete_query("templategroups", "prefix='pmedit'");
    
    // Also remove old template groups if exists (before the fix)
    $db->delete_query("templategroups", "prefix='pmEdit'");
    $db->delete_query("templategroups", "prefix='pm_edit'");

    // Remove task
    $db->delete_query("tasks", "file = 'pm_edit_prune_logs'");
}

function pm_edit_remove_templates()
{
    global $PL;

    // Use PluginLibrary to remove templates (same as Reactions plugin)
    if(file_exists(PLUGINLIBRARY))
    {
        require_once PLUGINLIBRARY;
        $PL = new PluginLibrary();
        $PL->templates_delete('pmedit', true);
    }
    else
    {
        // Fallback: manually remove templates
        global $db;

        $templates_dir = MYBB_ROOT.'inc/plugins/PM_Edit/Templates/';
        if(!is_dir($templates_dir)) {
            return;
        }

        $dir = opendir($templates_dir);
        while(($file = readdir($dir)) !== false)
        {
            if(preg_match('#\.html$#i', $file))
            {
                $template_name = str_replace('.html', '', $file);
                $db->delete_query("templates", "title='".$db->escape_string($template_name)."' AND sid='-2'");
            }
        }
        closedir($dir);
    }
}
