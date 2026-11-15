<?php
/**
 * Plugin Name: PM Edit
 * Description: Adds editing functionality to Private Messages in MyBB
 * Author: PARADOX
 * Version: 1.1.0
 * License: GPL-3.0
 */

// Disallow direct access to this file for security reasons
if(!defined("IN_MYBB"))
{
    die("Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.");
}

if (!defined("PLUGINLIBRARY")) {
    define("PLUGINLIBRARY", MYBB_ROOT . "inc/plugins/pluginlibrary.php");
}

// Load plugin functions
require_once MYBB_ROOT . "inc/plugins/PM_Edit/Functions/edit_functions.php";
require_once MYBB_ROOT . "inc/plugins/PM_Edit/Functions/permission_functions.php";

// Load admin module
require_once MYBB_ROOT . "inc/plugins/PM_Edit/Admin/module.php";

// Plugin info
function pm_edit_info()
{
    return array(
        "name" => "PM Edit",
        "description" => "Allows users to edit their received and sent private messages with edit history tracking.",
        "website"        => "https://mybb.epizy.com",
        "author"        => "PARAD0X",
        "authorsite"    => "https://community.mybb.com/user-134311.html",
        "version"        => "1.2.1",
        "codename" => "pm_edit",
        "guid"            => "",
        "compatibility" => "18*"
    );
}

// Hook to load SCEditor on private pages
$plugins->add_hook("private_start", "pm_edit_load_editor");

/**
 * Load SCEditor assets for PM quick edit
 * Uses the same approach as Quick Edit Editor - fetches codebuttons template
 */
function pm_edit_load_editor()
{
    global $mybb, $headerinclude, $templates, $lang, $theme, $smiliecache, $cache, $db;
    
    // Check if quick edit with SCEditor is enabled
    if(!isset($mybb->settings['pm_edit_quick_sceditor']) || $mybb->settings['pm_edit_quick_sceditor'] != 1)
    {
        return;
    }
    
    // Check if user can use code buttons
    if($mybb->user['uid'] && $mybb->user['showcodebuttons'] == 0)
    {
        return;
    }
    
    // Load language if needed
    if(!isset($lang->editor_title_bold))
    {
        $lang->load("global");
    }
    
    // Fetch the live codebuttons template from the database
    $template = '';
    
    // 1) Theme-specific version
    if(!empty($theme['templateset']))
    {
        $query = $db->simple_select('templates', 'template',
            "title='codebuttons' AND sid='".(int)$theme['templateset']."'");
        $result = $db->fetch_array($query);
        if($result && !empty($result['template']))
        {
            $template = $result['template'];
        }
    }
    
    // 2) MyBB master template (sid = -2)
    if(empty($template))
    {
        $query = $db->simple_select('templates', 'template', "title='codebuttons' AND sid='-2'");
        $result = $db->fetch_array($query);
        if($result && !empty($result['template']))
        {
            $template = $result['template'];
        }
    }
    
    // If we couldn't get the template, use fallback
    if(empty($template))
    {
        pm_edit_load_editor_fallback();
        return;
    }
    
    // Replace template placeholders with actual values
    $asset_url = $mybb->asset_url;
    $editortheme = $theme['editortheme'];
    $imgdir = $theme['imgdir'];
    
    $dollar = '$';
    $ph_asset_url = '{' . $dollar . 'mybb->asset_url}';
    $ph_editortheme_sq = '{' . $dollar . "theme['editortheme']}";
    $ph_imgdir_sq = '{' . $dollar . "theme['imgdir']}";
    
    $template = str_replace($ph_asset_url, $asset_url, $template);
    $template = str_replace($ph_editortheme_sq, $editortheme, $template);
    $template = str_replace($ph_imgdir_sq, $imgdir, $template);
    
    // Extract CSS link tags
    preg_match_all('/<link\b[^>]+>/i', $template, $css_matches);
    $css_tags = isset($css_matches[0]) ? $css_matches[0] : array();
    
    // Extract external script tags
    preg_match_all('/<script\b[^>]+\bsrc="([^"]+)"[^>]*>\s*<\/script>/i', $template, $js_matches, PREG_SET_ORDER);
    
    // Build header with CSS and JS from codebuttons template
    $header = "<!-- PM Edit: live assets from codebuttons -->\n";
    
    // Add CSS (deduplicated)
    $added_css = array();
    foreach($css_tags as $link_tag)
    {
        if(preg_match('/\bhref="([^"]+)"/i', $link_tag, $m))
        {
            if(!in_array($m[1], $added_css))
            {
                $added_css[] = $m[1];
                $header .= $link_tag . "\n";
            }
        }
    }
    
    // Add JS (deduplicated)
    $added_js = array();
    foreach($js_matches as $match)
    {
        $src = $match[1];
        if(!in_array($src, $added_js))
        {
            $added_js[] = $src;
            $header .= $match[0] . "\n";
        }
    }
    
    // Now add smilies and config
    $smilies_config = pm_edit_build_smilies();
    $toolbar = pm_edit_build_toolbar($smilies_config['emoticons_enabled']);
    
    // Get editor height
    $height = isset($mybb->settings['pm_edit_editor_height']) ? (int)$mybb->settings['pm_edit_editor_height'] : 250;
    if($height < 100) $height = 250;
    
    $rtl = $lang->settings['rtl'] ? 'true' : 'false';
    
    // Build the config and plugin JS
    $header .= '
<script type="text/javascript">
var partialmode = ' . ($mybb->settings['partialmode'] ? 'true' : 'false') . ',
quickedit_opt_editor = {
    plugins: "undo",
    format: "bbcode",
    bbcodeTrim: false,
    style: "'.$mybb->asset_url.'/jscripts/sceditor/themes/'.$theme['editortheme'].'",
    rtl: '.$rtl.',
    locale: "mybblang",
    width: "100%",
    height: '.$height.',
    enablePasteFiltering: true,
    autoUpdate: true,
    emoticonsEnabled: '.$smilies_config['emoticons_enabled'].',
    emoticons: {
        dropdown: {'.$smilies_config['dropdownsmilies'].'},
        more: {'.$smilies_config['moresmilies'].'},
        hidden: {'.$smilies_config['hiddensmilies'].'}
    },
    emoticonsCompat: true,
    toolbar: "'.$toolbar.'"
};
var mybblang = '.quick_editor_build_language($lang).';
</script>
<script type="text/javascript" src="'.$mybb->asset_url.'/inc/plugins/PM_Edit/Assets/pm_edit.js"></script>
';
    
    $header .= "\n<!-- /PM Edit Plugin -->\n";
    
    $headerinclude .= $header;
}

/**
 * Fallback header if codebuttons template can't be loaded
 */
function pm_edit_load_editor_fallback()
{
    global $mybb, $headerinclude, $lang, $theme, $cache, $smiliecache;
    
    // Load language if needed
    if(!isset($lang->editor_title_bold))
    {
        $lang->load("global");
    }
    
    $smilies_config = pm_edit_build_smilies();
    $toolbar = pm_edit_build_toolbar($smilies_config['emoticons_enabled']);
    
    $height = isset($mybb->settings['pm_edit_editor_height']) ? (int)$mybb->settings['pm_edit_editor_height'] : 250;
    if($height < 100) $height = 250;
    
    $rtl = $lang->settings['rtl'] ? 'true' : 'false';
    
    $headerinclude .= '
<link rel="stylesheet" href="'.$mybb->asset_url.'/jscripts/sceditor/themes/'.$theme['editortheme'].'" type="text/css" media="all" />
<script type="text/javascript" src="'.$mybb->asset_url.'/jscripts/sceditor/jquery.sceditor.bbcode.min.js"></script>
<script type="text/javascript" src="'.$mybb->asset_url.'/jscripts/bbcodes_sceditor.js"></script>
<script type="text/javascript">
var partialmode = ' . ($mybb->settings['partialmode'] ? 'true' : 'false') . ',
quickedit_opt_editor = {
    plugins: "undo",
    format: "bbcode",
    bbcodeTrim: false,
    style: "'.$mybb->asset_url.'/jscripts/sceditor/themes/'.$theme['editortheme'].'",
    rtl: '.$rtl.',
    locale: "mybblang",
    width: "100%",
    height: '.$height.',
    enablePasteFiltering: true,
    autoUpdate: true,
    emoticonsEnabled: '.$smilies_config['emoticons_enabled'].',
    emoticons: {
        dropdown: {'.$smilies_config['dropdownsmilies'].'},
        more: {'.$smilies_config['moresmilies'].'},
        hidden: {'.$smilies_config['hiddensmilies'].'}
    },
    emoticonsCompat: true,
    toolbar: "'.$toolbar.'"
};
var mybblang = '.quick_editor_build_language($lang).';
</script>
<script type="text/javascript" src="'.$mybb->asset_url.'/inc/plugins/PM_Edit/Assets/pm_edit.js"></script>
';
}

/**
 * Build smilies for SCEditor
 */
function pm_edit_build_smilies()
{
    global $mybb, $theme, $cache, $smiliecache;
    
    // Get smilie cache
    if(!$smiliecache)
    {
        $smiliecache = $cache->read("smilies");
    }
    
    // Pre-process smilie paths like MyBB does
    if(is_array($smiliecache))
    {
        foreach($smiliecache as $sid => $smilie)
        {
            $smiliecache[$sid]['image'] = str_replace("{theme}", $theme['imgdir'], $smilie['image']);
        }
    }
    
    $emoticons_enabled = "false";
    $dropdownsmilies = "";
    $moresmilies = "";
    $hiddensmilies = "";
    
    $smilieinserter = isset($mybb->settings['smilieinserter']) ? $mybb->settings['smilieinserter'] : 1;
    $smilieinsertercols = isset($mybb->settings['smilieinsertercols']) ? $mybb->settings['smilieinsertercols'] : 4;
    $smilieinsertertot = isset($mybb->settings['smilieinsertertot']) ? $mybb->settings['smilieinsertertot'] : 15;
    
    if($mybb->settings['pmsallowsmilies'] == 1 && $smilieinserter && $smilieinsertercols)
    {
        $emoticons_enabled = "true";
        
        if(is_array($smiliecache) && !empty($smiliecache))
        {
            $i = 0;
            foreach($smiliecache as $smilie)
            {
                if($smilie['showclickable'] != 0)
                {
                    $i++;
                }
                
                $finds = explode("\n", $smilie['find']);
                $first_find = $finds[0];
                
                // Use get_asset_url for proper path resolution
                $find = str_replace(array('\\', '"'), array('\\\\', '\"'), htmlspecialchars_uni($first_find));
                $image = htmlspecialchars_uni($mybb->get_asset_url($smilie['image']));
                $image = str_replace(array('\\', '"'), array('\\\\', '\"'), $image);
                
                if(!$smilieinserter || !$smilieinsertercols || !$smilieinsertertot || !$smilie['showclickable'])
                {
                    $hiddensmilies .= '"'.$find.'": "'.$image.'",';
                }
                elseif($i <= $smilieinsertertot)
                {
                    $dropdownsmilies .= '"'.$find.'": "'.$image.'",';
                }
                else
                {
                    $moresmilies .= '"'.$find.'": "'.$image.'",';
                }
                
                // Add additional find strings as hidden smilies
                for($j = 1; $j < count($finds); $j++)
                {
                    $find = str_replace(array('\\', '"'), array('\\\\', '\"'), htmlspecialchars_uni($finds[$j]));
                    $hiddensmilies .= '"'.$find.'": "'.$image.'",';
                }
            }
        }
    }
    
    return array(
        'emoticons_enabled' => $emoticons_enabled,
        'dropdownsmilies' => $dropdownsmilies,
        'moresmilies' => $moresmilies,
        'hiddensmilies' => $hiddensmilies
    );
}

/**
 * Build toolbar based on settings
 */
function pm_edit_build_toolbar($emoticons_enabled)
{
    global $mybb;
    
    $basic1 = $basic2 = $align = $font = $size = $color = $removeformat = $email = $link = $list = $code = $emoticon = "";
    
    if($mybb->settings['allowbasicmycode'] == 1)
    {
        $basic1 = "bold,italic,underline,strike|";
        $basic2 = "horizontalrule,";
    }
    
    if($mybb->settings['allowalignmycode'] == 1)
    {
        $align = "left,center,right,justify|";
    }
    
    if($mybb->settings['allowfontmycode'] == 1)
    {
        $font = "font,";
    }
    
    if($mybb->settings['allowsizemycode'] == 1)
    {
        $size = "size,";
    }
    
    if($mybb->settings['allowcolormycode'] == 1)
    {
        $color = "color,";
    }
    
    if($mybb->settings['allowfontmycode'] == 1 || $mybb->settings['allowsizemycode'] == 1 || $mybb->settings['allowcolormycode'] == 1)
    {
        $removeformat = "removeformat|";
    }
    
    if($mybb->settings['allowlinkmycode'] == 1)
    {
        $email = "email,";
        $link = "link,unlink|";
    }
    
    if($mybb->settings['allowlistmycode'] == 1)
    {
        $list = "bulletlist,orderedlist|";
    }
    
    if($mybb->settings['allowcodemycode'] == 1)
    {
        $code = "code,php";
    }
    
    if($emoticons_enabled == "true")
    {
        $emoticon = ",emoticon";
    }
    
    $toolbar = "{$basic1}{$align}{$font}{$size}{$color}{$removeformat}{$basic2}image,{$email}{$link}|video{$emoticon}|{$list}{$code}|quote|maximize,source";
    
    return $toolbar;
}

/**
 * Build editor language strings
 */
function quick_editor_build_language($lang)
{
    $lang_strings = array(
        'quote' => isset($lang->editor_quote) ? $lang->editor_quote : 'Quote',
        'bold' => isset($lang->editor_title_bold) ? $lang->editor_title_bold : 'Bold',
        'italic' => isset($lang->editor_title_italic) ? $lang->editor_title_italic : 'Italic',
        'underline' => isset($lang->editor_title_underline) ? $lang->editor_title_underline : 'Underline',
        'strike' => isset($lang->editor_title_strike) ? $lang->editor_title_strike : 'Strikethrough',
        'subscript' => isset($lang->editor_title_subscript) ? $lang->editor_title_subscript : 'Subscript',
        'superscript' => isset($lang->editor_title_superscript) ? $lang->editor_title_superscript : 'Superscript',
        'align_left' => isset($lang->editor_title_align_left) ? $lang->editor_title_align_left : 'Align Left',
        'align_center' => isset($lang->editor_title_align_center) ? $lang->editor_title_align_center : 'Center',
        'align_right' => isset($lang->editor_title_align_right) ? $lang->editor_title_align_right : 'Align Right',
        'align_justify' => isset($lang->editor_title_align_justify) ? $lang->editor_title_align_justify : 'Justify',
        'font' => isset($lang->editor_font) ? $lang->editor_font : 'Font',
        'size' => isset($lang->editor_size) ? $lang->editor_size : 'Size',
        'color' => isset($lang->editor_color) ? $lang->editor_color : 'Color',
        'removeformat' => isset($lang->editor_title_removeformat) ? $lang->editor_title_removeformat : 'Remove Format',
        'link' => isset($lang->editor_link) ? $lang->editor_link : 'Link',
        'unlink' => isset($lang->editor_title_unlink) ? $lang->editor_title_unlink : 'Unlink',
        'image' => isset($lang->editor_image) ? $lang->editor_image : 'Image',
        'email' => isset($lang->editor_email) ? $lang->editor_email : 'Email',
        'quote' => isset($lang->editor_quote) ? $lang->editor_quote : 'Quote',
        'code' => isset($lang->editor_code) ? $lang->editor_code : 'Code',
        'php' => isset($lang->editor_php) ? $lang->editor_php : 'PHP',
        'list' => isset($lang->editor_list) ? $lang->editor_list : 'List',
        'list_num' => isset($lang->editor_list_num) ? $lang->editor_list_num : 'Numbered List',
        'list_bullet' => isset($lang->editor_list_bullet) ? $lang->editor_list_bullet : 'Bullet List',
        'video' => isset($lang->editor_video) ? $lang->editor_video : 'Video',
        'maximize' => isset($lang->editor_title_maximize) ? $lang->editor_title_maximize : 'Maximize',
        'source' => isset($lang->editor_title_source) ? $lang->editor_title_source : 'Source',
        'youtube' => isset($lang->editor_title_youtube) ? $lang->editor_title_youtube : 'YouTube',
        'smileys' => isset($lang->editor_title_smileys) ? $lang->editor_title_smileys : 'Smileys',
        'insert_smiley' => isset($lang->editor_insert_smiley) ? $lang->editor_insert_smiley : 'Insert Smiley',
        'close' => isset($lang->editor_close) ? $lang->editor_close : 'Close',
        'saved' => isset($lang->editor_saved) ? $lang->editor_saved : 'Saved',
        'enter_url' => isset($lang->editor_enter_url) ? $lang->editor_enter_url : 'Enter URL:',
        'enter_email' => isset($lang->editor_enter_email) ? $lang->editor_enter_email : 'Enter Email:',
        'enter_text' => isset($lang->editor_enter_text) ? $lang->editor_enter_text : 'Enter Text:',
        'enter_image' => isset($lang->editor_enter_image) ? $lang->editor_enter_image : 'Enter Image URL:',
        'paste' => isset($lang->editor_paste) ? $lang->editor_paste : 'Paste',
        'paste_url' => isset($lang->editor_paste_url) ? $lang->editor_paste_url : 'Or paste URL:',
        'invalid_url' => isset($lang->editor_invalid_url) ? $lang->editor_invalid_url : 'Invalid URL',
        'invalid_email' => isset($lang->editor_invalid_email) ? $lang->editor_invalid_email : 'Invalid Email',
        'font_family' => isset($lang->editor_font_family) ? $lang->editor_font_family : 'Font Family',
        'font_size' => isset($lang->editor_font_size) ? $lang->editor_font_size : 'Font Size',
        'font_color' => isset($lang->editor_font_color) ? $lang->editor_font_color : 'Font Color',
        'custom_colors' => isset($lang->editor_custom_colors) ? $lang->editor_custom_colors : 'Custom Colors'
    );
    
    return json_encode($lang_strings);
}

// Install plugin
function pm_edit_install()
{
    global $db;

    // Load install script
    require_once MYBB_ROOT . "inc/plugins/PM_Edit/install.php";
    pm_edit_install_run();
}

// Check if installed
function pm_edit_is_installed()
{
    global $db;

    // Check if our edit history table exists
    return $db->table_exists("pm_edit_history");
}

// Uninstall plugin
function pm_edit_uninstall()
{
    global $db;

    // Load uninstall script
    require_once MYBB_ROOT . "inc/plugins/PM_Edit/uninstall.php";
    pm_edit_uninstall_run();
}

// Activate plugin
function pm_edit_activate()
{
    global $db;

    // Load install script for activation tasks
    require_once MYBB_ROOT . "inc/plugins/PM_Edit/install.php";
    pm_edit_activate_run();
}

// Deactivate plugin
function pm_edit_deactivate()
{
    global $db;

    // Remove template modifications
    require_once MYBB_ROOT . "inc/adminfunctions_templates.php";

    // Remove PM edit button from postbit templates
    find_replace_templatesets("postbit", "#" . preg_quote('{$post[\'button_edit_pm\']}') . "#i", '');
    find_replace_templatesets("postbit_classic", "#" . preg_quote('{$post[\'button_edit_pm\']}') . "#i", '');

    // Remove edit history link
    find_replace_templatesets("postbit", "#" . preg_quote('{$post[\'pm_edit_history\']}') . "#i", '');
    find_replace_templatesets("postbit_classic", "#" . preg_quote('{$post[\'pm_edit_history\']}') . "#i", '');

    // Remove CSS from headerinclude
    find_replace_templatesets("headerinclude", "#\s*<link rel=\"stylesheet\" href=\"\{\$mybb->asset_url\}/inc/plugins/PM_Edit/Assets/pm_edit\.css\"\s*/?>#i", '');
}

// Hook: Add PM edit button to postbit (for regular posts)
// Hook: Add PM edit button to postbit_pm (for private messages)
$plugins->add_hook("postbit", "pm_edit_postbit");
$plugins->add_hook("postbit_pm", "pm_edit_postbit");

// Hook: Add edit notice to postbit signature
$plugins->add_hook("postbit", "pm_edit_postbit_signature");

// Admin hooks
$plugins->add_hook("admin_tools_menu", "pm_edit_admin_menu");
$plugins->add_hook("admin_tools_action_handler", "pm_edit_admin_action_handler");
$plugins->add_hook("admin_load", "pm_edit_admin_load");

function pm_edit_postbit(&$post)
{
    global $mybb, $templates, $lang;

    // Initialize variables to prevent undefined array key errors
    $post['button_edit_pm'] = '';
    $post['pm_edit_history'] = '';

    // Only process if this is a PM
    if($post['pmid'] && $mybb->settings['pm_edit_enabled'] == 1)
    {
        // Load language
        $lang->load("pm_edit");

        // Check if user can edit this PM
        if(pm_edit_can_edit_pm($post))
        {
            // Add edit button
            $button_edit_pm = $templates->get("pmedit_edit_button");
            eval("\$post['button_edit_pm'] = \"{$button_edit_pm}\";");
        }

        // Check if there are edit history entries for this PM
        if(pm_edit_has_history($post['pmid']))
        {
            // Add history link
            $pm_edit_history = $templates->get("pmedit_edit_history_link");
            eval("\$post['pm_edit_history'] = \"{$pm_edit_history}\";");
        }
    }
}

// Hook: Handle PM edit actions
$plugins->add_hook("private_start", "pm_edit_action_handler");

// Hook: Handle PM quick edit AJAX
$plugins->add_hook("xmlhttp", "pm_edit_xmlhttp");

function pm_edit_action_handler()
{
    global $mybb, $db, $lang, $templates, $theme;

    $action = $mybb->get_input('action');

    // Handle edit PM page
    if($action == "editpm")
    {
        pm_edit_show_edit_form();
    }
    // Handle edit submission
    elseif($action == "do_editpm")
    {
        pm_edit_process_edit();
    }
    // Handle view edit history
    elseif($action == "pm_edit_history")
    {
        pm_edit_show_history();
    }
}

function pm_edit_xmlhttp()
{
    global $mybb, $db, $lang;

    $action = $mybb->get_input('action');

    // Handle quick edit PM
    if($action == "quick_edit_pm")
    {
        $pmid = $mybb->get_input('pmid', MyBB::INPUT_INT);
        $do = $mybb->get_input('do');

        if(!$pmid)
        {
            xmlhttp_error($lang->invalidpm);
        }

        // Load language
        $lang->load("pm_edit");

        // Get PM
        $pm = $db->fetch_array($db->simple_select("privatemessages", "*", "pmid = '{$pmid}'"));

        if(!$pm)
        {
            xmlhttp_error($lang->invalidpm);
        }

        // Check permissions
        if(!pm_edit_can_edit_pm($pm))
        {
            xmlhttp_error($lang->no_permission);
        }

        // Get PM content
        if($do == "get_pm")
        {
            // Use JSON_UNESCAPED_SLASHES to prevent double escaping
            header("Content-type: application/json; charset=utf-8");
            echo json_encode($pm['message'], JSON_UNESCAPED_SLASHES);
            exit;
        }
        // Update PM
        elseif($do == "update_pm")
        {
            if(!verify_post_check($mybb->get_input('my_post_key'), true))
            {
                xmlhttp_error($lang->invalid_post_code);
            }

            $message = $mybb->get_input('value');
            $editreason = $mybb->get_input('editreason');

            require_once MYBB_ROOT . "inc/plugins/PM_Edit/Functions/edit_functions.php";

            $result = pm_edit_update_pm($pmid, $message, $editreason);

            if($result['success'])
            {
                header("Content-type: application/json; charset=utf-8");
                echo json_encode(array(
                    'message' => $result['message'],
                    'editedmsg' => $result['editedmsg'],
                    'historylink' => $result['historylink']
                ));
                exit;
            }
            else
            {
                xmlhttp_error($result['errors']);
            }
        }
    }
}

// Hook: Add language variables
$plugins->add_hook("global_start", "pm_edit_language");

function pm_edit_language()
{
    global $lang;

    // Add language variables
    if(!isset($lang->pm_edit_button)) {
        $lang->pm_edit_button = "Edit";
    }
    if(!isset($lang->pm_edit_title)) {
        $lang->pm_edit_title = "Edit Private Message";
    }
    if(!isset($lang->pm_edit_reason)) {
        $lang->pm_edit_reason = "Edit Reason:";
    }
    if(!isset($lang->pm_edit_update)) {
        $lang->pm_edit_update = "Update Message";
    }
    if(!isset($lang->pm_edit_cancel)) {
        $lang->pm_edit_cancel = "Cancel";
    }
    if(!isset($lang->pm_edit_history)) {
        $lang->pm_edit_history = "Edit History";
    }
    if(!isset($lang->pm_edited_by)) {
        $lang->pm_edited_by = "Last edited by {1} on {2}.";
    }
}

// Hook: Add edit notice to PM read page
$plugins->add_hook("private_read_end", "pm_edit_private_read");

// Hook: Add edit notice to postbit signature
$plugins->add_hook("postbit", "pm_edit_postbit_signature");

// Hook: Add headerincludes
$plugins->add_hook("global_intermediate", "pm_edit_headerinclude");

/**
 * Add edit notice to postbit signature area
 */
function pm_edit_postbit_signature(&$post)
{
    global $mybb, $templates, $lang;

    // Only process if this is a PM (pmid exists)
    if(!empty($post['pmid']) && $mybb->settings['pm_edit_enabled'] == 1)
    {
        // Load language
        $lang->load("pm_edit");

        // Get edit notice
        $edit_notice = pm_edit_get_notice($post);

        if(!empty($edit_notice))
        {
            $pm_edit_notice_template = $templates->get("pmedit_edit_notice");
            eval("\$notice_html = \"{$pm_edit_notice_template}\";");

            // Append to signature or create new signature
            if(!empty($post['signature']))
            {
                $post['signature'] .= $notice_html;
            }
            else
            {
                $post['signature'] = $notice_html;
            }
        }
    }
}

function pm_edit_private_read()
{
    global $mybb, $db, $pm;

    // Always initialize edit_notice to prevent undefined array key error
    $pm['edit_notice'] = '';

    // Check if show edit notice is enabled
    if($mybb->settings['pm_edit_show_edit_notice'] != 1)
    {
        return;
    }

    // Load language
    global $lang;
    if(!isset($lang->pm_edit_button)) {
        $lang->load("pm_edit");
    }

    // Add edit notice to PM data if applicable
    if($mybb->settings['pm_edit_enabled'] == 1 && !empty($pm['edittime']))
    {
        // Get editor info
        $query = $db->simple_select("users", "username, usergroup, displaygroup", "uid = '{$pm['edituid']}'");
        $editor = $db->fetch_array($query);

        if($editor)
        {
            $editor_name = format_name($editor['username'], $editor['usergroup'], $editor['displaygroup']);
            $editor_name = build_profile_link($editor_name, $pm['edituid']);
            $edit_date = my_date('relative', $pm['edittime']);
            $pm['edit_notice'] = $lang->sprintf($lang->pm_edited_by, $editor_name, $edit_date);

            // Also add to pm['signature'] so it shows in postbit
            if(empty($pm['signature'])) {
                $pm['signature'] = '';
            }
        }
    }
}

function pm_edit_headerinclude()
{
    global $mybb, $template;

    if($mybb->settings['pm_edit_enabled'] == 1 && $template !== null) {
        // Add CSS to headerinclude
        $template = str_replace('</head>', '<link rel="stylesheet" href="{$mybb->asset_url}/inc/plugins/PM_Edit/Assets/pm_edit.css" />' . "\n</head>", $template);
        
        // Add JavaScript to headerinclude
        $template = str_replace('</head>', '<script src="{$mybb->asset_url}/inc/plugins/PM_Edit/Assets/pm_edit.js"></script>' . "\n</head>", $template);
    }
}

function pm_edit_add_task()
{
    global $db;

    // Check if task already exists
    $query = $db->simple_select('tasks', 'tid', "file = 'pm_edit_prune_logs'");
    if($db->num_rows($query) > 0)
    {
        return;
    }

    // Add the task
    $task = array(
        'title' => 'PM Edit Prune Logs',
        'description' => 'Deletes PM edit logs older than 7 days.',
        'file' => 'pm_edit_prune_logs',
        'minute' => '0',
        'hour' => '0',
        'day' => '*',
        'month' => '*',
        'weekday' => '0',
        'enabled' => '1',
        'logging' => '1'
    );
    
    $db->insert_query('tasks', $task);
}

function pm_edit_remove_task()
{
    global $db;

    // Remove the task
    $db->delete_query('tasks', "file = 'pm_edit_prune_logs'");
}