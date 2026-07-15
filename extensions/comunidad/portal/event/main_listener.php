<?php
namespace comunidad\portal\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class main_listener implements EventSubscriberInterface
{
    protected $helper;
    protected $template;
    protected $user;

    public function __construct(
        \phpbb\controller\helper $helper,
        \phpbb\template\template $template,
        \phpbb\user $user
    ) {
        $this->helper = $helper;
        $this->template = $template;
        $this->user = $user;
    }

    public static function getSubscribedEvents()
    {
        return [
            'core.user_setup' => 'load_language',
            'core.page_header' => 'add_navigation_link',
        ];
    }

    public function load_language($event)
    {
        $lang_set_ext = $event['lang_set_ext'];
        $lang_set_ext[] = [
            'ext_name' => 'comunidad/portal',
            'lang_set' => 'common',
        ];
        $event['lang_set_ext'] = $lang_set_ext;
    }

    public function add_navigation_link()
    {
        $this->template->assign_vars([
            'U_COMMUNITY_PORTAL' => $this->helper->route('comunidad_portal_index'),
        ]);
    }
}
