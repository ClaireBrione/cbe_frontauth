<?php

// This is a PLUGIN TEMPLATE for Textpattern CMS.

// Copy this file to a new name like abc_myplugin.php.  Edit the code, then
// run this file at the command line to produce a plugin for distribution:
// $ php abc_myplugin.php > abc_myplugin-0.1.txt

// Plugin name is optional.  If unset, it will be extracted from the current
// file name. Plugin names should start with a three letter prefix which is
// unique and reserved for each plugin author ("abc" is just an example).
// Uncomment and edit this line to override:
$plugin['name'] = 'cbe_frontauth';

// Allow raw HTML help, as opposed to Textile.
// 0 = Plugin help is in Textile format, no raw HTML allowed (default).
// 1 = Plugin help is in raw HTML.  Not recommended.
$plugin['allow_html_help'] = 1;

$plugin['version'] = '0.9.6';
$plugin['author'] = 'Claire Brione';
$plugin['author_uri'] = 'http://www.clairebrione.com/';
$plugin['description'] = 'Manage backend connections from frontend';

// Plugin load order:
// The default value of 5 would fit most plugins, while for instance comment
// spam evaluators or URL redirectors would probably want to run earlier
// (1...4) to prepare the environment for everything else that follows.
// Values 6...9 should be considered for plugins which would work late.
// This order is user-overrideable.
$plugin['order'] = '4';

// Plugin 'type' defines where the plugin is loaded
// 0 = public              : only on the public side of the website (default)
// 1 = public+admin        : on both the public and admin side
// 2 = library             : only when include_plugin() or require_plugin() is called
// 3 = admin               : only on the admin side (no AJAX)
// 4 = admin+ajax          : only on the admin side (AJAX supported)
// 5 = public+admin+ajax   : on both the public and admin side (AJAX supported)
$plugin['type'] = '0';

// Plugin "flags" signal the presence of optional capabilities to the core plugin loader.
// Use an appropriately OR-ed combination of these flags.
// The four high-order bits 0xf000 are available for this plugin's private use
if (!defined('PLUGIN_HAS_PREFS')) define('PLUGIN_HAS_PREFS', 0x0001); // This plugin wants to receive "plugin_prefs.{$plugin['name']}" events
if (!defined('PLUGIN_LIFECYCLE_NOTIFY')) define('PLUGIN_LIFECYCLE_NOTIFY', 0x0002); // This plugin wants to receive "plugin_lifecycle.{$plugin['name']}" events

$plugin['flags'] = '0';

// Plugin 'textpack' is optional. It provides i18n strings to be used in conjunction with gTxt().
// Syntax:
// ## arbitrary comment
// #@event
// #@language ISO-LANGUAGE-CODE
// abc_string_name => Localized String

/** Uncomment me, if you need a textpack
$plugin['textpack'] = <<< EOT
#@admin
#@language en-gb
abc_sample_string => Sample String
abc_one_more => One more
#@language de-de
abc_sample_string => Beispieltext
abc_one_more => Noch einer
EOT;
**/
// End of textpack

if (!defined('txpinterface'))
        @include_once('zem_tpl.php');

# --- BEGIN PLUGIN CODE ---
/*
** cbe_frontauth
** Client-side textpattern plugin
** Connect (and disconnect) from frontend to backend
** Establishes bidirectional links between article display and edition
** Claire Brione - http://www.clairebrione.com/
**
** 0.1-dev - 22 Jul 2011 - Restricted development release
** 0.2-dev - 23 Jul 2011 - Restricted development release
** 0.3-dev - 24 Jul 2011 - Restricted development release
** 0.4-beta- 26 Jul 2011 - Restricted beta release
** 0.5-beta- 27 Jul 2011 - First public beta release
** 0.6-beta- 29 Jul 2011 - Optimizations to avoid multiple calls to database
**                           when retrieving user's informations
**                         Added name and privilege controls
**                           à la <txp:rvm_if_privileged /> (http://vanmelick.com/txp/)
**                         Minor changes to documentation
** 0.7-beta- 06 Aug 2011 - Introduces <txp:cbe_frontauth_edit_article />
**                         CSRF protection ready
**                         Documentation improvements
** 0.7.1   - 05 Jan 2012 - Documentation addenda
** 0.8     - 10 Jan 2012 - Introduces <txp:cbe_frontauth_loginwith />
**                             http://forum.textpattern.com/viewtopic.php?pid=256632#p256632
**                         txp:cbe_frontauth_loginwith (*auto*|username|email)
** 0.9     - 21 Mar 2012 - Added callback hooks (reset and change password)
** 0.9.1   - 22 Mar 2012 - Fixed missing attributes (show_login and show_change) for cbe_frontauth_box
** 0.9.2   - ?? ??? 2012 - ??
** 0.9.3   - 22 Aug 2012 - Doc typo for cbe_frontauth_invite
** 0.9.4   - 27 Mar 2013 - Missing initialization for cbe_frontauth_whois
**                         Error message when login fails
**                         Local language strings
** 0.9.5   - 04 Apr 2014 - Missing last access storage
** 0.9.6   - 07 Apr 2014 - Error when passing presentational attributes from cbe_frontauth_edit_article to cbe_frontauth_link
**
** TODO
** - break, breakclass -> in progress, full tests needed
** - enhence error messages ?
**
 ************************************************************************/

/**************************************************
 **
 ** Local language strings, possible customisation here
 **
 **************************************************/
function _cbe_fa_lang()
{
    return( array( 'login_failed'         => "Login failed"
                 , 'login_to_textpattern' => gTxt( 'login_to_textpattern' )
                 , 'name'                 => gTxt( 'name' )
                 , 'password'             => gTxt( 'password' )
                 , 'log_in_button'        => gTxt( 'log_in_button' )
                 , 'stay_logged_in'       => gTxt( 'stay_logged_in' )
                 , 'logout'               => gTxt( 'logout' )
                 , 'edit'                 => gTxt( 'edit' )
                 , 'change_password'      => gTxt( 'change_password' )
                 , 'password_reset'       => gTxt( 'password_reset' ) 
                 )
          ) ;
}
/**************************************************
 **
 ** Don't edit further
 **
 **************************************************/

/**************************************************
 **
 ** Available tags
 **
 **************************************************/

/* == Shortcuts for cbe_frontauth() == */

// -- Global init for redirection after login and/or logout
// -------------------------------------------------------------------
function cbe_frontauth_redirect( $atts )
{
    return( _cbe_fa_init( $atts, 'redir' ) ) ;
}

// -- Global init for login/logout invites
// -------------------------------------------------------------------
function cbe_frontauth_invite( $atts )
{
    return( _cbe_fa_init( $atts, 'invite' ) ) ;
}

// -- Global init for login/logout buttons/link labels
// -------------------------------------------------------------------
function cbe_frontauth_label( $atts )
{
    return( _cbe_fa_init( $atts, 'label' ) ) ;
}

// -- Global init for login with user name, email, or automatic detection
// -------------------------------------------------------------------
function cbe_frontauth_loginwith( $atts )
{
    return( _cbe_fa_init( $atts, 'with' ) ) ;
}

// -- Login / Logout box
// -------------------------------------------------------------------
function cbe_frontauth_box( $atts, $thing = '' )
{
    $public_atts = lAtts( array( 'login_invite'  => _cbe_fa_gTxt( 'login_to_textpattern' )
                               , 'logout_invite' => ''
                               , 'show_change'   => '1'
                               , 'show_reset'    => '1'
                               , 'tag_invite'    => ''
                               , 'login_label'   => _cbe_fa_gTxt( 'log_in_button' )
                               , 'logout_label'  => _cbe_fa_gTxt( 'logout' )
                               , 'logout_type'   => 'button'
                               , 'tag_error'     => 'span'
                               , 'class_error'   => 'cbe_fa_error'
                               )
                           + _cbe_fa_format()
                         , $atts ) ;

    return( cbe_frontauth( $public_atts
                          , $thing ? $thing : '<p><txp:text item="logged_in_as" /> <txp:cbe_frontauth_whois wraptag="span" class="user"/></p>'
                          ) ) ;

}

// -- Standalone login form
// -------------------------------------------------------------------
function cbe_frontauth_login( $atts, $thing = '' )
{
    return( _cbe_fa_inout_process( 'login', $atts, $thing ) ) ;
}

// -- Standalone logout form / link
// -------------------------------------------------------------------
function cbe_frontauth_logout( $atts, $thing = '' )
{
    return( _cbe_fa_inout_process( 'logout', $atts, $thing ) ) ;
}

// -- Protect parts from non-connected viewers
// -------------------------------------------------------------------
function cbe_frontauth_protect( $atts, $thing )
{
    $public_atts = lAtts( array( 'link'      => ''
                               , 'linklabel' => ''
                               , 'target'    => '_self'
                               , 'name'      => ''
                               , 'level'     => ''
                               )
                         + _cbe_fa_format()
                         , $atts ) ;

    if( $public_atts['target'] == '_get' )
        $public_atts['target'] = '_self' ;

    return( cbe_frontauth( array( 'login_invite' => '' , 'logout_invite' => ''
                                , 'show_login'   => '0', 'show_logout'   => '0'
                                , 'show_reset'   => '0', 'show_change'   => '0' ) + $public_atts
                         , $thing
                         ) ) ;
}
function cbe_frontauth_if_logged( $atts, $thing )
{
    return( cbe_frontauth_protect( $atts, $thing ) ) ;
}
function cbe_frontauth_if_connected( $atts, $thing )
{
    return( cbe_frontauth_protect( $atts, $thing ) ) ;
}

/* == Elements == */

// -- Generates input field for name
// -------------------------------------------------------------------
function cbe_frontauth_logname( $atts, $defvalue=null )
{
    return( _cbe_fa_identity( 'name', $atts, $defvalue ) ) ;
}

// -- Generates input field for password
// -------------------------------------------------------------------
function cbe_frontauth_password( $atts, $defvalue=null )
{
    return( _cbe_fa_identity( 'password', $atts, $defvalue ) ) ;
}

// -- Generates checkbox for stay (connected on this browser)
// -------------------------------------------------------------------
function cbe_frontauth_stay( $atts )
{
    extract( lAtts( array ( 'label' => _cbe_fa_gTxt( 'stay_logged_in' )
                          )
                  + _cbe_fa_format()
                  , $atts
                  )
           ) ;

    $out  = checkbox('p_stay', 1, cs('txp_login'), '', 'stay') ;
    $out .= '<label for="stay">'.$label.'</label>' ;
    return( doTag( $out, $wraptag, $class ) ) ;
}

// -- Generates submit button
// -------------------------------------------------------------------
function cbe_frontauth_submit( $atts )
{
    $public_atts = lAtts( array ( 'label' => ''
                                , 'type'  => 'login'
                                )
                        + _cbe_fa_format()
                        , $atts
                        ) ;

    return( _cbe_fa_button( $public_atts ) ) ;
}

// -- Displays connected user's informations
// -------------------------------------------------------------------
function cbe_frontauth_whois( $atts )
{
    extract( lAtts( array ( 'type'       => 'name'
                          , 'format'     => ''
                          )
                    + _cbe_fa_format()
                  , $atts
                  )
           ) ;

    $types = do_list( $type ) ;
    $whois = cbe_frontauth( array( 'init' => '0', 'value' => $types ) ) ;

    if( isset( $whois['last_access'] ) )
    {
        global $dateformat ;
        $whois['last_access'] = safe_strftime( $format ? $format : $dateformat, strtotime( $whois['last_access'] ) ) ;
    }

    return( doWrap( $whois, $wraptag, $break, $class, $breakclass ) ) ;
}

/* == Off-topic, but useful == */

// -- Generates a link, normal or with a GET parameter
// -------------------------------------------------------------------
function cbe_frontauth_link( $atts )
{   // $class applies to anchor if no $wraptag supplied
    extract( lAtts( array ( 'label'  => ''
                          , 'link'   => ''
                          , 'target' => '_self'
                          )
                  + _cbe_fa_format()
                  , $atts
                  )
           ) ;

    $link = doStripTags( $link ) ;
    $out = _cbe_fa_link( compact( 'link', 'target' ) ) ;
    $out = href( $label, $out
               , (($target !== '_get')  ? ' target="'.$target.'"' : '')
               . ((!$wraptag && $class) ? ' class="'.$class.'"'   : '') ) ;
    return( doTag( $out, $wraptag, $class ) ) ;
}

// -- Returns path to textpattern backend
// -------------------------------------------------------------------
function cbe_frontauth_backend()
{
//            . substr(strrchr(txpath, "/"), 1)
    return( preg_replace('|//$|','/', rhu.'/')
            . substr(strrchr(txpath, DS), 1)
            . '/index.php'
           ) ;
}

// -- Returns button (standalone) or link to edit current article
// -------------------------------------------------------------------
function cbe_frontauth_edit_article( $atts )
{
    global $thisarticle ;
    assert_article() ;

    extract( lAtts( array ( 'label' => _cbe_fa_gTxt( 'edit' )
                          , 'type'  => 'button'
                          )
                  + _cbe_fa_format()
                  , $atts
                  )
           ) ;

    $path_parts = array( 'event'      => 'article'
                       , 'step'       => 'edit'
                       , 'ID'         => $thisarticle['thisid']
                       ) ;

    if( $type == 'button' )
    {
        $out = array() ;

        foreach( $path_parts as $part => $value )
            $out[] = hInput( $part, $value ) ;

        $out[] = cbe_frontauth_submit( array( 'label' => $label, 'type' => '', 'class' =>'publish' ) ) ;

         return( _cbe_fa_form( array( 'statements' => join( n, $out ) ) ) ) ;
    }
    elseif( $type == 'link' )
    {
        $path_parts[ '_txp_token' ] = form_token() ;
        array_walk( $path_parts, create_function( '&$v, $k', '$v = $k."=".$v ;' ) ) ;
        $link = cbe_frontauth_backend() . '?' . join( '&', $path_parts ) ;

        return( cbe_frontauth_link( compact( 'link', 'label'
                                           , array_keys( _cbe_fa_format() )
                                           )
                                  )
              ) ;
    }
    else
        return ;
}

/**************************************************
 **
 ** Utilities (kinda private functions)
 **
 **************************************************/

// -- Gets and returns local lang strings (txp admin + plugin specifics)
// -------------------------------------------------------------------
function _cbe_fa_gTxt( $text, $atts = array() )
{
    static $aTexts = array() ;
    if( ! $aTexts )
        $aTexts = _cbe_fa_lang() ;

    return( isset( $aTexts[ $text ] ) ? strtr( $aTexts[ $text ], $atts ) : gTxt( $text ) ) ;
}

// -- Common presentational attributes
// -------------------------------------------------------------------
function _cbe_fa_format()
{
    return( array( 'wraptag'    => ''
                 , 'class'      => ''
                 , 'break'      => ''
                 , 'breakclass' => ''
                 )
          ) ;
}

// -- Global initialisations (redirect, invite, label, loginwith)
// -------------------------------------------------------------------
function _cbe_fa_init( $atts, $type )
{
    extract( lAtts( array ( 'for' => '', 'value' => '' ), $atts ) ) ;
    if( $for === '' )
        $for = 'login' ;
    $init_for = do_list( $for ) ;

    if( ($index=array_search( 'logged', $init_for )) !== false )
        $init_for[ $index ] = 'logout' ;

    array_walk( $init_for, create_function( '&$v, $k, $p', '$v = $v."_".$p ;'), $type ) ;

    if( ($init_list = @array_combine( $init_for, do_list( $value ) )) === false )
        return ;

    cbe_frontauth( array( 'init' => '1' ) + $init_list ) ;
    return ;
}

// -- Retrieve user's info, if connected 
// -- textpattern/lib/txp_misc.php - is_logged_in() as a starting point
// -------------------------------------------------------------------
function _cbe_fa_logged_in( &$user, $txp_user = null )
{
    if( $txp_user !== null )
        $name = $txp_user ;
    elseif( !($name = substr(cs('txp_login_public'), 10)) )
    {
        $user[ 'name' ] = false ;
        return( false ) ;
    }

    $rs = safe_row('nonce, name, RealName, email, privs, last_access', 'txp_users', "name = '".doSlash($name)."'");

    if( $rs && ($txp_user !== null || substr(md5($rs['nonce']), -10) === substr(cs('txp_login_public'), 0, 10) ) )
    {
        unset( $rs[ 'nonce' ] ) ;
        $user = $rs ;
        return( true ) ;
    }
    else
    {
        $user[ 'name' ] = false ;
        return( false ) ;
    }
}

// -- Checks current user against required privileges
// -- Thanks to Ruud Van Melick's rvm_privileged (http://vanmelick.com/txp/)
// -------------------------------------------------------------------
function _cbe_fa_privileged( $r_name, $r_level, $u_name, $u_level )
{
    $chk_name  = !$r_name  || in_array( $u_name , do_list( $r_name  ) ) ;
    $chk_level = !$r_level || in_array( $u_level, do_list( $r_level ) ) ;
    return( $chk_name && $chk_level ) ;
}

// -- Generates input field for name or password
// -------------------------------------------------------------------
function _cbe_fa_identity( $field, $atts, $value=null )
{
    extract( lAtts( array ( 'label'     => _cbe_fa_gTxt( $field )
                          , 'label_sfx' => ''
                          )
                  + _cbe_fa_format()
                  , $atts
                  )
           ) ;

    $$field = '' ;
    if( $field == 'name' && cs('cbe_frontauth_login') != '' )
        list($name) = explode( ',', cs('cbe_frontauth_login') ) ;

    $out = array() ;
    $out[] = '<label for="'.$field.$label_sfx.'">'.$label.'</label>' ;
    $out[] =  fInput( ($field == 'name')    ? 'text'     : 'password'
                    ,(($field == 'name')    ? 'p_userid' : 'p_password') . $label_sfx
                    , ($field == 'name')    ? $name      : ($value !== null ? $value : '')
                    , (!$wraptag && $class) ? $class     : ''
                    , '', '', '', '', $field.$label_sfx ) ;

    return( doWrap( $out, $wraptag, $break, $class, $breakclass ) ) ;
}

// -- Prepare call to cbe_frontauth() for login/logout form/link
// -------------------------------------------------------------------
function _cbe_fa_inout_process( $inout, $atts, $thing = '' )
{
    $plus_atts = ($inout == 'logout' )
               ? array( 'type'        => 'button'
                      , 'show_change' => '1'      )
               : array( 'show_stay'   => '0'
                      , 'show_reset'  => '1'      ) ;
    $public_atts = lAtts( array ( 'invite'     => _cbe_fa_gTxt( ($inout == 'login') ? 'login_to_textpattern' 
                                                                                    : '' )
                                , 'tag_invite' => ''
                                , 'label'      => _cbe_fa_gTxt( ($inout == 'login') ? 'log_in_button'
                                                                                    : 'logout' )
                                , 'form'       => ''
                                , 'tag_error'  => 'span', 'class_error' => 'cbe_fa_error'
                                )
                        + $plus_atts + _cbe_fa_format()
                        , $atts ) ;

    if( isset( $public_atts['invite'] ) )
    {
        $public_atts[$inout.'_invite'] = $public_atts['invite'] ;
        unset( $public_atts['invite'] ) ;
    }
    if( isset( $public_atts['label'] ) )
    {
        $public_atts[$inout.'_label'] = $public_atts['label'] ;
        unset( $public_atts['label'] ) ;
    }
    if( isset( $public_atts['form'] ) )
    {
        $public_atts[$inout.'_form'] = $public_atts['form'] ;
        unset( $public_atts['form'] ) ;
    }
    if( isset( $public_atts['type'] ) )
    {
        $public_atts[$inout.'_type'] = $public_atts['type'] ;
        unset( $public_atts['type'] ) ;
    }
    if( $thing )
        $public_atts[$inout.'_form'] = $thing ;

    $show = ($inout == 'login') ? 'logout' : 'login' ;
    return( cbe_frontauth( array( 'show_'.$show => '0' ) + $public_atts ) ) ;
}

// -- Encloses statements in a submit form
// -------------------------------------------------------------------
function _cbe_fa_form( $atts )
{
    extract( lAtts( array( 'statements' => ''
                         , 'action'     => cbe_frontauth_backend()
                         , 'method'     => 'post'
                         )
                  , $atts
                  )
           ) ;

    if( ! $statements )
        return ;

    return( '<form action="'.$action.'" method="'.$method.'">'
            .n. $statements
            .n. '</form>' ) ;
}

// -- Generates a button (primary purpose : login/logout button)
// -- Extended to 'edit' (just in case) - 0.7
// -- Note: providing a label and setting type to blank works too
// -------------------------------------------------------------------
function _cbe_fa_button( $atts )
{
    extract( $atts ) ; // 'label', 'type', 'wraptag', class'

    if( ! $label and ! ($label = cbe_frontauth( array( 'init' => '0', 'value' => $type.'_label' ) )) )
        $label = _cbe_fa_gTxt( ($type == 'logout' || $type == 'edit' ) ? $type : 'log_in_button' ) ;

    $out = fInput( 'submit', '', $label, (!$wraptag && $class) ? $class : '' ) ;
 
    if( $type == 'logout' )
        $out .= hInput( 'p_logout', '1' ) ;
    elseif( $type == 'edit' )
        $out .= tInput() ;

    return( doTag( $out, $wraptag, $class ) ) ;
}

// -- Generates a link (primary purpose : logout link)
// -------------------------------------------------------------------
function _cbe_fa_link( $atts )
{
    extract( $atts ) ; // 'link', 'target'
    
    if( $target == '_get' )
    {
        $uri = serverSet( 'REQUEST_URI'  ) ;
        $qus = serverSet( 'QUERY_STRING' ) ;

        $len_uri = strlen( $uri ) ;
        $len_qus = strlen( $qus ) ;

        $uri = ($len_qus > 0) ? substr( $uri, 0, $len_uri-$len_qus-1 ) : $uri ;
        $qus = $qus . ($len_qus > 0 ? '&' : '') . $link ;

        $out = (substr( $uri, -1 ) !== '?' ) ? ($uri.'?'.$qus) : ($uri.$qus) ;
    }
    else
    {
        $out = $link ;
    }

    return( $out ) ;
}

// -- Generates login/logout form or logout link
// -------------------------------------------------------------------
function _cbe_fa_inout( $atts )
{
    extract( $atts ) ;

    $out = array() ;

    if( $form )
        $out[] = ($f=@fetch_form( $form )) ? parse( $f ) : parse( $form ) ; // label takes precedence here
    else
    {
        if( isset( $show_stay ) )
        {   // login

            $out[] = cbe_frontauth_logname(  array( 'class' => 'edit')
                                          +  compact( 'break', 'breakclass' ) ) ;
            $out[] = cbe_frontauth_password( array( 'class' => 'edit')
                                           + compact( 'break', 'breakclass' ) ) ;
            if( $show_stay )
                $out[] = cbe_frontauth_stay( array() ) ;

            $out[] = cbe_frontauth_submit( array( 'label' => $label, 'class' => 'publish' ) ) ;

        }
        else
        {   // logout
            $out[] = ($type == 'button')
                   ? cbe_frontauth_submit( array( 'label' => $label, 'type' => 'logout'
                                                , 'class' => $class ? $class : 'publish' ) )
                   : cbe_frontauth_link( array( 'label' => $label, 'link' => 'logout=1', 'target' => '_get'
                                              , 'class' => $class ? $class : 'publish' ) ) ;
        }
    }

//    $out = join( n, $out ) ;
    $out = doWrap( $out, $wraptag, $break, '', $breakclass ) ;
    return( (isset( $type ) && $type=='link')
            ? $out
            : _cbe_fa_form( array( 'statements' => $out, 'action' => page_url( array() ) ) ) ) ;
}

/* == Backbone == */

// -- Cookie mechanism - from textpattern/include/txp_auth.php - doTxpValidate()
// -------------------------------------------------------------------
function _cbe_fa_auth( $redir, $p_logout, $p_userid='', $p_password='', $p_stay='' )
{
    defined('LOGIN_COOKIE_HTTP_ONLY') || define('LOGIN_COOKIE_HTTP_ONLY', true);
    $hash  = md5(uniqid(mt_rand(), TRUE));
    $nonce = md5($p_userid.pack('H*',$hash));
    $pub_path = preg_replace('|//$|','/', rhu.'/') ;
    $adm_path = $pub_path . substr(strrchr(txpath, DS), 1) . '/' ;

    if( $p_logout )
    {
        $log_name = false ;

        safe_update( 'txp_users'
                   , "nonce = '".doSlash($hash)."'"
                   , "name = '".doSlash($p_userid)."'"
                   ) ;

        setcookie( 'txp_login'
                 , ''
                 , time()-3600
                 , $adm_path
                 ) ;

        setcookie( 'txp_login_public'
                 , ''
                 , time()-3600
                 , $pub_path
                 ) ;

        setcookie( 'cbe_frontauth_login'
                 , ''
                 , time()-3600
                 , $pub_path
                 ) ;
    }
//    elseif( ($log_name = txp_validate( $p_userid, $p_password, false )) !== false )
    elseif( ($log_name = txp_validate( $p_userid, $p_password )) !== false )
    {
        safe_update( 'txp_users'
                   , "nonce = '".doSlash($nonce)."'"
                   , "name = '".doSlash($p_userid)."'"
                   ) ;

        setcookie( 'txp_login'
                 , $p_userid.','.$hash
                 , ($p_stay ? time()+3600*24*365 : 0)
                 , $adm_path
                 , null
                 , null
                 , LOGIN_COOKIE_HTTP_ONLY
                 ) ;

        setcookie( 'txp_login_public'
                 , substr(md5($nonce), -10).$p_userid
                 , ($p_stay ? time()+3600*24*30 : 0)
                 , $pub_path
                 ) ;

        if( $p_stay )
            setcookie( 'cbe_frontauth_login'
                     , $p_userid.','.$hash
                     , time()+3600*24*365
                     , $pub_path
                     ) ;
    }

    if( $redir && ( $p_logout || $log_name !== false ) )
    {
        header( "Location:$redir" ) ;
        exit ;
    }

    return( $log_name ) ;
}

// -- Get the job done
// -------------------------------------------------------------------
function cbe_frontauth( $atts, $thing = null )
{
    include_once( txpath.'/include/txp_auth.php' ) ;
    global $txp_user ;
    static $inits = array( 'login_invite' => '' , 'logout_invite' => '' , 'tag_invite' => ''
                         , 'login_label'  => '' , 'logout_label'  => ''
                         , 'login_redir'  => '' , 'logout_redir'  => ''
                         , 'login_with'   => ''
                         ) ;
    static $cbe_fa_user = array( 'name'  => false , 'RealName'    => '' , 'email' => ''
                               , 'privs' => ''    , 'last_access' => ''
                               ) ;

    if( isset( $atts['init'] ) )
    {
        if( $atts['init'] )
        {
            unset( $atts['init'] ) ;

            foreach( $atts as $param => $value )
                $inits[$param] = $value ;

            return ;
        }
        else
        {
            if( is_array( $atts[ 'value' ] ) )
            {
                $whois = array() ;
                if( ! $cbe_fa_user[ 'name' ] ) _cbe_fa_logged_in( $cbe_fa_user ) ;
                foreach( $atts[ 'value' ] as $type )
                    $whois[ $type ] = $cbe_fa_user[ $type ] ;
                
                return( $whois ) ;
            }
            else
                return( isset( $inits[ $atts[ 'value' ] ] ) ? $inits[ $atts[ 'value' ] ] : '' ) ;
        }
    }

    $def_atts = array( 'form'          => ''
                     , 'tag_invite'    => ''
                     , 'show_login'    => '1'
                     , 'login_invite'  => _cbe_fa_gTxt( 'login_to_textpattern' )
                     , 'login_form'    => ''
                     , 'login_label'   => _cbe_fa_gTxt( 'log_in_button' )
                     , 'login_with'    => 'auto'
                     , 'login_redir'   => ''
                     , 'show_logout'   => '1'
                     , 'logout_invite' => ''
                     , 'logout_form'   => ''
                     , 'logout_label'  => _cbe_fa_gTxt( 'logout' )
                     , 'logout_type'   => 'button'
                     , 'logout_redir'  => ''
                     , 'show_stay'     => '0'
                     , 'show_reset'    => '1'
                     , 'show_change'   => '1'
                     , 'link'          => ''
                     , 'linklabel'     => ''
                     , 'target'        => '_self'
                     , 'name'          => ''
                     , 'level'         => ''
                     , 'tag_error'     => ''
                     , 'class_error'   => ''
                     ) ;

    $ini_atts = array() ;
    foreach( $inits as $param => $value )
    {   /* Inits take precedence on default values */
        if( !isset( $atts[$param] ) || $atts[$param] === $def_atts[$param] )
            $ini_atts[$param] = $value ;
    }

    extract( lAtts( $def_atts + _cbe_fa_format(), array_merge( $atts, array_filter( $ini_atts ) ) ) ) ;

    extract( psa( array( 'p_userid', 'p_password', 'p_stay', 'p_reset', 'p_logout', 'p_change' ) ) ) ;
    $logout = gps( 'logout' ) ;
    $p_logout = $p_logout || $logout ;
    $reset = gps( 'reset' ) ;
    $p_reset = $p_reset || $reset ;
    $change = gps( 'change' ) ;
    $p_change = $p_change || $change ;

    if( $p_userid && $p_password )
    {
        $username = ($login_with == 'auto') ? safe_count( 'txp_users', "name='$p_userid'" ) : 0 ;

        if( $username == 0 && $login_with != 'username' )
        { // Email probably given, retrieve user name if possible
            $p_userid = safe_rows( 'name', 'txp_users', "email='$p_userid'" ) ;
            $p_userid = (count( $p_userid ) == 1) ? $p_userid[ 0 ][ 'name' ] : '' ;
        }

        $login_redir = ($login_redir==='link') ? $link : $login_redir ;
        $login_failed = ($txp_user = _cbe_fa_auth( $login_redir, 0, $p_userid, $p_password, $p_stay )) === false ;
        _cbe_fa_logged_in( $cbe_fa_user, $txp_user ) ;
    }
    elseif( $p_logout )
    {
        if( $logout && !$logout_redir )
            $logout_redir = preg_replace( "/[?&]logout=1/", "", serverSet('REQUEST_URI') ) ;

        $txp_user = _cbe_fa_auth( $logout_redir, 1 ) ;
        _cbe_fa_logged_in( $cbe_fa_user, false ) ;
    }
    else
        $txp_user = _cbe_fa_logged_in( $cbe_fa_user ) ? $cbe_fa_user[ 'name' ] : false ;

    $out = array() ;
    $invite = '' ;
    $part_0 = EvalElse( $thing, 0 ) ;
    $part_1 = EvalElse( $thing, 1 ) ;
    if( $txp_user === false )
    {
        $out[] = parse( $part_0 ) ;

        if( $show_login )
        {
            if( $p_reset )
            {   // Resetting password in progress
                $invite = _cbe_fa_gTxt( 'password_reset' ) ;
                $out[]  = callback_event( 'cbefrontauth.reset_password', 'cbe_fa_before_login', 0
                                        , ps('step') ? array( 'p_userid'   => $p_userid
                                                            , 'login_with' => $login_with
                                                            , 'tag_error'  => $tag_error
                                                            , 'class_error' => $class_error )
                                                     : null ) ;
            }
            else
            {   // We are not resetting the password at the moment, display login form
                if( isset( $login_failed ) && $login_failed )
                    $out[] = doTag( _cbe_fa_gTxt( 'login_failed' ), $tag_error, $class_error ) ;
                $invite = $login_invite ;
                $out[]  = _cbe_fa_inout( array( 'label'      => $login_label
                                              , 'form'       => $login_form
                                              , 'show_stay'  => $show_stay
                                              , 'show_reset' => $show_reset
                                              ) + compact( 'wraptag', 'class', 'break', 'breakclass' ) ) ;
                if( $show_reset )
                    $out[] = callback_event( 'cbefrontauth.reset_password', 'cbe_fa_after_login' ) ;
            }
        }
    }
    else
    {
        if( (!$name && !$level)
            ||
            _cbe_fa_privileged( $name, $level, $cbe_fa_user[ 'name' ], $cbe_fa_user[ 'privs' ] )
          )
        {
            if( $link )
                $out[] = cbe_frontauth_link( array( 'label' => $linklabel ) + compact( 'link', 'target' ) ) ;

            if( $thing )
                $out[] = parse( $part_1 ) ;
            elseif( $form )
                $out[] = parse_form( $form ) ;
        }
        else
            $out[] = parse( $part_0 ) ;

        if( $show_logout )
        {
            if( $p_change )
            {   // Changing password in progress
                $invite = _cbe_fa_gTxt( 'change_password' ) ;
                $out[]  = callback_event( 'cbefrontauth.change_password', 'cbe_fa_before_logout', 0
                                        , ps('step') ? array( 'p_userid'     => $txp_user
                                                            , 'p_password'   => $p_password
                                                            , 'p_password_1'
                                                               => strip_tags( ps( 'p_password_1' ) )
                                                            , 'p_password_2'
                                                               => strip_tags( ps( 'p_password_2' ) )
                                                            , 'tag_error'    => $tag_error
                                                            , 'class_error'  => $class_error )
                                                     : null ) ;
            }
            else
            {   // We are not changing the password at the moment, display logout form
                $invite = $logout_invite ;
                $out[] = _cbe_fa_inout( array( 'label'       => $logout_label
                                             , 'form'        => $logout_form
                                             , 'type'        => $logout_type
                                             , 'show_change' => $show_change
                                             , 'p_change'    => $p_change
                                             , 'tag_error'   => $tag_error
                                             , 'class_error' => $class_error
                                             ) + compact( 'wraptag', 'class', 'break', 'breakclass' ) ) ;
                if( $show_change )
                    $out[] = callback_event( 'cbefrontauth.change_password', 'cbe_fa_after_logout' ) ;
            }
        }
    }

//    return( doLabel( $invite, $tag_invite ) . doWrap( $out, $wraptag, $break, $class, $breakclass ) ) ;
    return( doLabel( $invite, $tag_invite ) . doWrap( $out, $wraptag, '', $class ) ) ;
}

# --- END PLUGIN CODE ---
if (0) {
?>
<!--
# --- BEGIN PLUGIN CSS ---
<style type="text/css">
#cbe-plugin-help .readme {
  color: red;
  font-weight: bold;
}
#cbe-plugin-help .accent {
  font-weight: bold;
}
#cbe-plugin-help .list th{
	text-align:left;
	color:#fff;
	background:#555;
	padding:6px 16px 6px 4px;
	border-right:1px solid #999;
	font-weight:normal;
}
#cbe-plugin-help .list th a{
	color:#fff;
	display:block;
}
#cbe-plugin-help .list th h2{
	margin:0;
	font-size:11px;
}
#cbe-plugin-help .list td{
	padding:6px 4px;
}
</style>
# --- END PLUGIN CSS ---
-->
<!--
# --- BEGIN PLUGIN HELP ---
<div id="cbe-plugin-help">
<h1>cbe_frontauth</h1>

<p>This client-side plugin lets your users (or you) manage backend connection from frontend, i.e. connect and disconnect as they (you) would do from backend.
<br />You can thus make things visible and open actions for connected users only.</p>
<p>Developed and tested with Textpattern 4.4.1, then 4.5-beta.</p>
<p class="readme">Please read the first <a href="#quick-start">Quick start</a> paragraph to avoid (as much as possible) unexpected behaviors.</p>
<p>A few examples (in french) can be found in the <a href="http://www.clairebrione.com/demo-cbe_frontauth">demonstration page</a>.</p>

<h2>Table of contents</h2>
<ul>
  <li><a href="#features">Features</a></li>
  <li><a href="#dl-install-supp">Download, installation, support</a></li>
  <li><a href="#tags-list">Tags list</a></li>
  <li><a href="#notations">Notations</a></li>
  <li><a href="#quick-start">Quick start</a></li>
  <li><a href="#individual-elements">Take control on individual elements</a></li>
  <li><a href="#additional-tags">Additional and special tags</a></li>
  <li><a href="#callbacks">Callbacks</a></li>
  <li><a href="#how-to">How-to: ideas and snippets</a></li>
  <li><a href="#advanced-usage">Advanced usage</a></li>
  <li><a href="#changelog">Changelog</a></li>
</ul>

<h2 id="features">Features</h2>

<ul>
  <li>automatically generate a <a href="#login-logout-box">login/logout box</a>...</li>
  <li>... or independent <a href="#login-area">login</a> or <a href="#logout-area">logout</a> forms and links</li>
  <li>choose if th user must connect with his/her <a href="#login-method">username, or email address, or one of both</a></li>
  <li>show/hide content depending on whether a user is connected or not (see <a href="#protect-parts">protect parts of a page</a>)</li>
  <li>set <a href="#automatic-redirect">automatic redirections</a> after login and/or logout</li>
  <li>optional: define your own default values for <a href="#setting-invites">login/logout invites</a> and <a href="#setting-labels">button/link labels</a>, <a href="#login-method">define login method</a></li>
  <li>override these values anywhere in the page if you need to</li>
  <li>also provides <a href="#additional-tags">additional tags</a> to ease scripter's life</li>
  <li>and <a href="#callbacks">hooks for callback functions</a> primarily in order to reset or change user's password</li>
</ul>

<h2 id="dl-install-supp">Download, installation, support</h2>
<p>Download from <a href="http://textpattern.org/plugins/1234/cbe_frontauth">textpattern resources</a> or the <a href="http://www.clairebrione.com/cbe_frontauth">plugin page</a>.</p>
<p>Copy/paste in the Admin > Plugins tab to install or uninstall, activate or desactivate.</p>
<p>Visit the <a href="http://forum.textpattern.com/viewtopic.php?id=36552">forum thread</a> for support.</p>

<h2 id="tags-list">Tags list</h2>

<p>Alphabetically:
<br /><a href="#advanced-usage">cbe_frontauth</a>
<br /><a href="#path-backend">cbe_frontauth_backend</a>
<br /><a href="#login-logout-box">cbe_frontauth_box</a>
<br /><a href="#edit-article">cbe_frontauth_edit_article</a>
<br /><a href="#protect-parts">cbe_frontauth_if_connected</a>
<br /><a href="#setting-invites">cbe_frontauth_invite</a>
<br /><a href="#setting-labels">cbe_frontauth_label</a>
<br /><a href="#link-generation">cbe_frontauth_link</a>
<br /><a href="#protect-parts">cbe_frontauth_if_logged</a>
<br /><a href="#login-area">cbe_frontauth_login</a>
<br /><a href="#login-method">cbe_frontauth_loginwith</a>
<br /><a href="#form-elements">cbe_frontauth_logname</a>
<br /><a href="#logout-area">cbe_frontauth_logout</a>
<br /><a href="#form-elements">cbe_frontauth_password</a>
<br /><a href="#protect-parts">cbe_frontauth_protect</a>
<br /><a href="#automatic-redirect">cbe_frontauth_redirect</a>
<br /><a href="#form-elements">cbe_frontauth_reset</a>
<br /><a href="#form-elements">cbe_frontauth_stay</a>
<br /><a href="#form-elements">cbe_frontauth_submit</a>
<br /><a href="#user-info">cbe_frontauth_whois</a>
</p>

<h2 id="notations">Notations</h2>

<p><code>Tags and examples are presented with this typography</code> (fixed width).</p>
<p>Possible values for attributes are separated by a "<code> | </code>" (pipe).</p>
<p><b>Bold</b> means default value.</p>
<p>"<code>...</code>" (ellipsis) is to be replaced by any custom value, usually a string.</p>
<p>Attributes surrounded by "<code>[</code>" and "<code>]</code>" (square brackets) are optional.</p>

<h2 id="quick-start">Quick start</h2>

<p>Message strings are customisable by editing them in the function _cbe_fa_lang(). When possible, their default values are pulled from the language table. In most cases, you won't have to edit them as they are already localised.</p>

<p class="readme">What you have to know and care about :</p>
<ul>
  <li>The login/logout mechanism relies on cookies. A cookie is attached to one, and only one, subdomain.</li>
  <li><code>http://domain.tld</code> and <code>http://www.domain.tld</code> are <b>different</b> subdomains, even if you present the same content through both URLs.</li>
</ul>
<p>=> You will have to choose which base URL you want to use (with or without www) and stick to it along all the navigation. This is also a good practice to avoid duplicate content.</p>

<p>Here is how to:</p>
<p>1. Plugin load order: as it handles redirections, it has to be loaded before any other plugin. Set by default to <b>4</b>, adjust according to your needs.</p>
<p>2. Admin > Preferences : give (or verify) your site URL and save.</p>
<p>3. Edit the <code>.htaccess</code> file located at the root of your site, and append as closer as possible to <code>RewriteEngine On</code> (replace <code>domain.tld</code> with your actual URL):</p>

<p>EITHER, with www</p>
<pre><code>RewriteCond %{HTTP_HOST} !^www\.domain\.tld$ [NC]
RewriteRule ^(.*) http://www.domain.tld/$1 [QSA,R=301,L]</code></pre>

<p>OR, without www</p>
<pre><code>RewriteCond %{HTTP_HOST} ^www\.domain\.tld$ [NC]
RewriteRule ^(.*) http://domain.tld/$1 [QSA,R=301,L]</code></pre>

<p><span class="accent">It's time now to start using the plugin</span>: <a href="#login-logout-box">allow users to login and logout</a>, <a href="#automatic-redirect">redirecting them</a> (or not) after login and/or logout, <a href="#protect-parts">serve them special content</a>, <a href="#individual-elements">the rest is up to you</a>.</p>

<p><code>wraptag</code>, <code>class</code>, <code>break</code> and <code>breakclass</code> are supported by every tag and both default to <b>unset</b>.</p>

<h3 id="login-logout-box">Login/logout box: &lt;txp:cbe_frontauth_box /&gt;</h3>

<pre><code>&lt;txp:cbe_frontauth_box
  [ login_invite="<b>Connect to textpattern</b> | ..."
    logout_invite="<b>none</b> | ..."
    tag_invite="..."
    login_label="..."
    logout_label="..."
    logout_type="<b>button</b> | link"
    tag_error="<b>span</b>" class_error="<b>cbe_fa_error</b>"
    wraptag="..." class="..." break="..." breakclass="..." ] /&gt;</code></pre>

<p>Displays
<br />- simple login form if not connected
<br />- "connected as {login name}" and a logout button or link if connected
</p>

<p>If login fails, a basic message will appear just before the login form. You can customise its wrapping tag and class.</p>

<p>If you don't want "connected as" message, use as a container tag and put a blank or <a href="#box-ideas">anything else</a> in between:</p>
<pre><code>&lt;txp:cbe_frontauth_box&gt; &lt;/txp:cbe_frontauth_box&gt;</code></pre>

<h3 id="protect-parts">Protect parts of a page: &lt;txp:cbe_frontauth_protect /&gt;, &lt;txp:cbe_frontauth_if_logged /&gt; and &lt;txp:cbe_frontauth_if_connected /&gt;</h3>

<pre><code>&lt;txp:cbe_frontauth_protect
  [ name="<b>none</b> | comma-separated values"
    level="<b>none</b> | comma-separated values"
    link="<b>none</b> | url"
    linklabel="<b>none</b> | anchor"
    target="<b>_self</b> | _blank"
    wraptag="..." class="..." break="..." breakclass="..." ]&gt;
  What to protect
&lt;txp:else /&gt;
  What to display if not connected
&lt;/txp:cbe_frontauth_protect&gt;</code></pre>
<p>Synonyms: <code>&lt;txp:cbe_frontauth_if_connected /&gt;</code> <code>&lt;txp:cbe_frontauth_if_logged /&gt;</code> if you find one of these forms more convenient</p>

<p>If connected, you can automatically add a link to click to go somewhere. This link will show first (before any other content).
<br />You do this using the attributes <code>link</code>, <code>linklabel</code>, optionally <code>target</code> ("_self" opens the link in the same window/tab, "_blank" in a new window/tab).
</p>

<p>If you want to display the link anywhere else, or display more than one link, or conditionally show a link, prefer <a href="#link-generation">&lt;txp:cbe_frontauth_link /&gt;</a></p>

<h3 id="login-method">Login method &lt;txp:cbe_frontauth_loginwith /&gt;</h3>

<p>What to use as login name : username (as textpattern usually does), email, or auto for automatic detection.</p>
<p><span class="readme">Caution if using email login method</span> : textpattern doesn't check for duplicate email addresses upon user creation. If someone tries to log in using such an address, it will fail.</p>

<pre><code>&lt;txp:cbe_frontauth_loginwith
    value="<b>auto</b> | username | email" /&gt;</code></pre>

<h3 id="automatic-redirect">Automatic redirect: &lt;txp:cbe_frontauth_redirect /&gt;</h3>

<p>User will be automatically redirected after successful login and/or logout.
<br />Use this tag before any other cbe_frontauth_* as it sets redirection(s) for the whole page.</p>

<pre><code>&lt;txp:cbe_frontauth_redirect
    for="login | logout | login,logout"
    value="after_login_url | after_logout_url | after_login_url,after_logout_url" /&gt;</code></pre>

<p>In other words and in details:</p>
<p><code>&lt;txp:cbe_frontauth_redirect for="login" value="after_login_url" /&gt;</code>
<br />sets automatic redirection after login</p>
<p><code>&lt;txp:cbe_frontauth_redirect for="logout" value="after_logout_url" /&gt;</code>
<br />sets automatic redirection after logout</p>
<p><code>&lt;txp:cbe_frontauth_redirect for="login" value="after_login_url" /&gt;
&lt;txp:cbe_frontauth_redirect for="logout" value="after_logout_url" /&gt;</code>
<br />sets automatic redirection for both</p>
<p><code>&lt;txp:cbe_frontauth_redirect for="login,logout" value="after_login_url,after_logout_url" /&gt;</code>
<br />sets automatic redirection for both too</p>

<h3 id="setting-invites">Setting invites globally for the whole page: &lt;txp:cbe_frontauth_invite /&gt;</h3>

<p>Works the same way <a href="#automatic-redirect">as above</a>:</p>
<p><pre><code>&lt;txp:cbe_frontauth_invite for="..." value="..." /&gt;</code></pre></p>
<p>Combinations: <code>login, logout (or logged), tag</code></p>
<p>Synonym: <code>logged</code> for logout, if you find this form more convenient. <span class="accent">As synonyms they are mutually exclusive</span> and if both used <code>logout</code> will take precedence.</p>
<p>Can be overridden by any tag that has <code>invite</code> as attribute.</p>

<p>Example:</p>
<pre><code>&lt;txp:cbe_frontauth_invite for="login,logout,tag" invite="Please login,You can logout here,h2" /&gt;
&lt;txp:cbe_frontauth_box /&gt;
  ... Your page here ...
  ... and in the footer, for example ...
&lt;txp:cbe_frontauth_login invite="Say hello !" tag_invite="span" /&gt;
</code></pre>

<h3 id="setting-labels">Setting button and link labels globally for the whole page: &lt;txp:cbe_frontauth_label /&gt;</h3>

<p>Works the same way <a href="#automatic-redirect">as above</a> too:</p>
<p><pre><code>&lt;txp:cbe_frontauth_label for="..." value="..." /&gt;</code></pre></p>
<p>Combinations: <code>login, logout</code></p>
<p>Can be overridden by any tag that has <code>label</code> as attribute.</p>

<h2 id="individual-elements">Take control on individual elements</h2>

<h3 id="login-area">Login area: &lt;txp:cbe_frontauth_login /&gt;</h3>

<pre><code>&lt;txp:cbe_frontauth_login
  [ invite="<b>Connect to textpattern</b> | ..."
    tag_invite="<b>none</b> | ..."
    ( {label="<b>Login</b>|..." show_stay="<b>0</b>|1" show_reset="0|<b>1</b>"} | form="<b>none</b>|form name" )
    tag_error="<b>span</b>" class_error="<b>cbe_fa_error</b>"
    wraptag="..." class="..." break="..." breakclass="..." ] /&gt;

&lt;txp:cbe_frontauth_login
  [ invite="<b>Connect to textpattern</b> | ..."
    tag_invite="<b>none</b> | ..."
    tag_error="<b>span</b>" class_error="<b>cbe_fa_error</b>"
    wraptag="..." class="..." break="..." breakclass="..." ]&gt;
   form elements
&lt;/txp:cbe_frontauth_login&gt;</code></pre>

<p>If login fails, a basic message will appear just before the login form. You can customise its wrapping tag and class.</p>

<p id="form-elements" >Where <code>form elements</code> are:</p>
<pre><code>&lt;txp:cbe_frontauth_logname [label="<b>Name</b>|..." wraptag="..." class="..." break="..." breakclass="..."] /&gt;
&lt;txp:cbe_frontauth_password [label="<b>Password</b>|..." wraptag="..." class="..." break="..." breakclass="..."] /&gt;
&lt;txp:cbe_frontauth_stay [label="<b>Stay connected with this browser</b>|..." wraptag="..." class="..." break="..." breakclass="..."] /&gt;
&lt;txp:cbe_frontauth_reset [label="<b>Password forgotten ?</b>|..." wraptag="..." class="..." break="..." breakclass="..."] /&gt;
&lt;txp:cbe_frontauth_submit [label="<b>Login</b>|..." wraptag="..." class="..." break="..." breakclass="..."] /&gt;</code></pre>

<h3 id="logout-area">Logout area: &lt;txp:cbe_frontauth_logout /&gt;</h3>

<pre><code>&lt;txp:cbe_frontauth_logout
  [ invite="<b>none</b>|..."
    tag_invite="<b>none</b>|..."
    ( {label="<b>Logout</b>|..." type="<b>button</b>|link" show_change="0|<b>1</b>} | form="<b>none</b>|form name")
    wraptag="..." class="..." break="..." breakclass="..." ] /&gt;

&lt;txp:cbe_frontauth_logout
  [ invite="<b>none</b>|..."
    tag_invite="<b>none</b>|..."
    wraptag="..." class="..." break="..." breakclass="..." ]&gt;
   form elements
&lt;/txp:cbe_frontauth_logout&gt;</code></pre>

<p>Where <code>form elements</code> are:</p>
<pre><code>&lt;txp:cbe_frontauth_submit type="logout" [label="<b>Logout</b>|..." wraptag="..." class="..." break="..." breakclass="..."] /&gt;
&lt;txp:cbe_frontauth_link link="logout=1" target="_get" [label="..." wraptag="..." class="..." break="..." breakclass="..."] /&gt;</code></pre>

<h2 id="additional-tags">Additional and special tags</h2>

<h3 id="user-info">Connected user information: &lt;txp:cbe_frontauth_whois /&gt;</h3>

<pre><code>&lt;txp:cbe_frontauth_whois [type="[<b>name</b>][, RealName][, email][, privs][, last_access]" format="<b>as set in preferences</b>|since|rfc822|iso8601|w3cdtf|strftime() string value" wraptag="..." break="..." class="..." breakclass="..."] /&gt;</code></pre>
<p><code>format</code> applies to <code>last_access</code> if present.</p>

<h3 id="path-backend">Path to Textpattern backend: &lt;txp:cbe_frontauth_backend /&gt;</h3>

<pre><code>&lt;txp:cbe_frontauth_backend /&gt;</code></pre>

<p>Returns path to textpattern root (in most cases /textpattern/index.php).</p>

<h3 id="edit-article">Direct button or link to edit current article (write article)</h3>

<p>In an individual article form or enclosed in <code>&lt;txp:if_individual_article&gt; &lt;/txp:if_individual_article&gt;</code>:</p>

<pre><code>&lt;txp:cbe_frontauth_if_connected&gt;
    &lt;txp:cbe_frontauth_edit_article label="<b>edit</b>|..."  type="<b>button</b>|link" wraptag="..." class="..." break="..." breakclass="..." /&gt;
&lt;/txp:cbe_frontauth_if_connected&gt;</code></pre>

<p><span class="accent">Why use a button rather than a link ?</span> Answer: as it is enclosed in an HTML form, it allows to go to the edit page without showing parameters in the URL.</p>

<h3 id="link-generation">Link generation: &lt;txp:cbe_frontauth_link /&gt;</h3>

<pre><code>&lt;txp:cbe_frontauth_link label="..." link="..." [target="<b>_self</b>|_blank|_get" wraptag="..." class="..." break="..." breakclass="..."] /&gt;</code></pre>

<p><code>class</code> applies to the anchor if there is no <code>wraptag</code> supplied.</p>
<p><code>_get</code> will add <code>link</code> to the current URL, for example:</p>
<p>URL : http://www.example.com/page
<br /><code>&lt;txp:cbe_frontauth_link label="Logout" link="logout=1" target="_get" /&gt;</code>
<br />URL Result : http://www.example.com/page?logout=1</p>

<h2 id="callbacks">Callbacks</h2>
<p>They have been introduced to hook cbe_frontauth's companion, <a href="/cbe_members">cbe_members</a> (see details in the table below).</p>
<table class="list">
  <tr> <th>Event</th> <th>Step</th> <th>What it is</th> </tr>
  <tr> <td><code>cbefrontauth.reset_password</code></td> <td><code>cbe_fa_before_login</code></td> <td>Triggered before showing login form, when resetting password is in progress.<br />If cbe_members is installed, displays here the "reset password" form, or performs the actual reset if the form is successfully filled in.</td> </tr>
  <tr> <td><code>cbefrontauth.reset_password</code></td> <td><code>cbe_fa_after_login</code></td> <td>Triggered after showing login form.<br />If cbe_members is installed, displays a link to the "reset password" form.</td> </tr>
  <tr> <td><code>cbefrontauth.change_password</code></td> <td><code>cbe_fa_before_logout</code></td> <td>Triggered before showing logout form, when changing password is in progress.<br />If cbe_members is installed, displays the "change password" form, or performs the actual change if the form is successfully filled in.</td> </tr>
  <tr> <td><code>cbefrontauth.change_password</code></td> <td><code>cbe_fa_after_logout</code></td> <td>Triggered after showing logout form.<br />If cbe_members is installed, displays a link to the "change password" form.</td> </tr>
</table>

<h2 id="how-to">How-to: ideas and snippets</h2>

<h3 id="box-ideas">For login/logout box</h3>

<p>Replace the standard message with something else:</p>
<pre><code>&lt;txp:cbe_frontauth_box&gt;Welcome !&lt;/txp:cbe_frontauth_box&gt;</code></pre>

<p>Or even:</p>
<pre><code>&lt;txp:cbe_frontauth_box&gt;Greetings &lt;txp:cbe_frontauth_whois type="RealName" /&gt; !&lt;/txp:cbe_frontauth_box&gt;</code></pre>

<h3 id="invites-ideas">For invites</h3>
<pre><code>&lt;txp:cbe_frontauth_invite for="logged" value='&lt;txp:cbe_frontauth_whois type="RealName" /&gt;' /&gt;</code></pre>
<p>Note: if a user is connected, the login invite doesn't show and the logout invite takes its place. So we could use <code>for="logout"</code> as well.</p>

<h3 id="greeting-message">A greeting message</h3>
<pre><code>Greetings &lt;txp:cbe_frontauth_if_connected&gt;&lt;txp:cbe_frontauth_whois type="RealName" /&gt;&lt;txp:else /&gt;dear User&lt;/txp:cbe_frontauth_if_connected&gt; !</code></pre>

<h2 id="advanced-usage">Advanced usage</h2>

<p class="readme">As previous tags should cover majority's needs, you don't have to read this section if you already achieved what you wanted to.</p>

<p>This is the programmer's corner: it describes attributes for the main function that is called by almost every public tag discussed above.</p>

<p>Here are the parameters for the main function:</p>
<pre><code>&lt;txp:cbe_frontauth&gt;
  What to do/display if connected
&lt;txp:else /&gt;
  What to do/display if not connected
&lt;/txp:cbe_frontauth&gt;</code></pre>

<p>form ('') or thing = what to display if logged in
<br />tag_invite ('') = HTML tag enclosing the label, without brackets
<br />
<br />show_login (1) = whether to display or not a login form, appears only if not logged in
<br />- login_invite ('login_to_textpattern') = invite to login
<br />- login_form ('') = form to build your own HTML login form with txp:cbe_frontauth_login, or txp:cbe_frontauth_logname, cbe_frontauth_password, cbe_frontauth_stay, cbe_frontauth_reset, cbe_frontauth_submit. If not used, a default HTML form is displayed
<br />- login_label ('log_in_button') = label for the login form
<br />- login_with (auto) = whether to use username, or email, or auto detection as user logon
<br />- login_redir ('') = go immediately to path after successful login
<br />- show_stay (0) = used in the generic login form, whether to display or not a checkbox to stay logged in
<br />- show_reset (1) = used in the generic login form, whether to display or not a link to reset password
<br />
<br />show_logout (1) = whether to display or not a default button to log out, appears only if logged in
<br />- logout_invite ('') = invite to logout
<br />- logout_form ('') = form to build your own HTML logout form, or your own link
<br />- logout_label (as set in lang pack) = label for the logout button
<br />- logout_type ('button'), other type is 'link'
<br />- logout_redir ('') = go immediately to path after logout
<br />- show_change (1) = used in the generic logout form, whether to display or not a link to change password
<br />
<br />link ('') = a page to go to if connected
<br />- linklabel ('') = text anchor for link
<br />- target (_self) = _self _blank or _get, whether to open the link in the same window (or tab), or in a new one, or to generate an URL with address link as GET parameter. Works only with hyperlink (not login_redir, not logout_redir)
<br />
<br />Checking users and privileges :
<br />- name ('') = list of names to check
<br />- level ('') = list of privilege levels to check
<br />
<br />Presentational attributes :
<br />- wraptag (''), class ('')
<br />
<br />init = Special attribute for internal use only and documented only for people who want to know :)
 <br /> Whether to set ('1') or get ('0') global settings for redirections (login_redir, logout_redir), invites (login_invite, logout_invite, tag_invite), labels (login_label, logout_label), login type (login_with) or user's informations. Immediately returns and doesn't display anything.
 <br /> value = setting to set or get, string or array.
</p>

<h2 id="changelog">Changelog</h2>
<ul>
  <li>07 Apr 14 - v0.9.6 - Error when passing presentational attributes from cbe_frontauth_edit_article to cbe_frontauth_link</li>
  <li>04 Apr 14 - v0.9.5 - Missing last access storage</li>
  <li>27 Mar 13 - v0.9.4
  <br />Missing initialization for cbe_frontauth_whois
  <br />Error message when login fails
  <br />Local language strings
  </li>
  <li>22 Mar 12 - v0.9.3 - Doc typo for cbe_frontauth_invite</li>
  <li>?? ??? 12 - v0.9.2 - ??</li>
  <li>22 Mar 12 - v0.9.1 - fixed missing attributes (show_login and show_change) for cbe_frontauth_box</li>
  <li>21 Mar 12 - v 0.9 - Callback hooks: ability to ask for password reset if not connected, for password change if connected</li>
  <li>10 Jan 12 - v 0.8 - Introduces &lt;txp:cbe_frontauth_loginwith /&gt;, <a href="http://forum.textpattern.com/viewtopic.php?pid=256632#p256632">idea comes from another demand in the textpattern forum</a>.
  </li>
  <li>05 Jan 12 - v0.7.1 - Documentation addenda</li>
  <li>06 Aug 11 - v0.7-beta
  <br /> * Introduces &lt;txp:cbe_frontauth_edit_article /&gt
  <br /> * CSRF protection ready
  <br /> * Documentation improvements
  </li>
  <li>29 Jul 11 - v0.6-beta
  <br /> * Optimizations to avoid multiple calls to database when retrieving user's informations
  <br /> * Added name and privilege controls à la <a href="http://vanmelick.com/txp/">&lt;txp:rvm_if_privileged /&gt;</a>
  <br /> * Minor changes to documentation
  </li>
  <li>27 Jul 11 - v0.5-beta- First public beta release</li>
  <li>26 Jul 11 - v0.4-beta- Restricted beta release</li>
  <li>24 Jul 11 - v0.3-dev - Restricted development release</li>
  <li>23 Jul 11 - v0.2-dev - Restricted development release</li>
  <li>22 Jul 11 - v0.1-dev - Restricted development release</li>
</ul>
</div>
# --- END PLUGIN HELP ---
-->
<?php
}
?>
