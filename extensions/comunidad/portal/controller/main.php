<?php
namespace comunidad\portal\controller;

class main
{
    protected $config;
    protected $db;
    protected $template;
    protected $user;
    protected $auth;
    protected $helper;
    protected $root_path;
    protected $php_ext;

    public function __construct(
        \phpbb\config\config $config,
        \phpbb\db\driver\driver_interface $db,
        \phpbb\template\template $template,
        \phpbb\user $user,
        \phpbb\auth\auth $auth,
        \phpbb\controller\helper $helper,
        $root_path,
        $php_ext
    ) {
        $this->config = $config;
        $this->db = $db;
        $this->template = $template;
        $this->user = $user;
        $this->auth = $auth;
        $this->helper = $helper;
        $this->root_path = $root_path;
        $this->php_ext = $php_ext;
    }

    public function index()
    {
        $this->user->add_lang_ext('comunidad/portal', 'common');

        $this->assign_news();
        $this->assign_recent_topics(8);
        $this->assign_popular_topics(5);
        $this->assign_new_members(6);
        $this->assign_forums(8);

        $this->template->assign_vars([
            'PORTAL_TITLE'       => $this->config['sitename'],
            'PORTAL_DESCRIPTION' => $this->config['site_desc'],
            'TOTAL_POSTS'        => isset($this->config['num_posts']) ? (int) $this->config['num_posts'] : 0,
            'TOTAL_TOPICS'       => isset($this->config['num_topics']) ? (int) $this->config['num_topics'] : 0,
            'TOTAL_USERS'        => isset($this->config['num_users']) ? (int) $this->config['num_users'] : 0,
            'NEWEST_USERNAME'    => isset($this->config['newest_username']) ? $this->config['newest_username'] : '',
            'U_FORUM_INDEX'      => append_sid("{$this->root_path}index.{$this->php_ext}"),
            'U_SEARCH_ACTIVE'    => append_sid("{$this->root_path}search.{$this->php_ext}", 'search_id=active_topics'),
            'U_SEARCH_UNANSWERED'=> append_sid("{$this->root_path}search.{$this->php_ext}", 'search_id=unanswered'),
            'U_MEMBERLIST'       => append_sid("{$this->root_path}memberlist.{$this->php_ext}"),
            'S_USER_LOGGED_IN'   => $this->user->data['user_id'] != ANONYMOUS,
            'S_NEWS_ENABLED'     => (int) $this->config['portal_news_forum_id'] > 0,
        ]);

        return $this->helper->render('portal_body.html', $this->user->lang('PORTAL'));
    }

    protected function assign_news()
    {
        $forum_id = isset($this->config['portal_news_forum_id']) ? (int) $this->config['portal_news_forum_id'] : 0;
        if ($forum_id === 0) {
            return;
        }

        $limit = isset($this->config['portal_news_limit']) ? (int) $this->config['portal_news_limit'] : 3;
        if ($limit < 1) {
            $limit = 1;
        } elseif ($limit > 10) {
            $limit = 10;
        }

        $sql = 'SELECT t.topic_id, t.forum_id, t.topic_title, t.topic_time,
                       t.topic_poster, t.topic_first_poster_name, t.topic_first_poster_colour,
                       p.post_id, p.post_text, p.bbcode_uid
                FROM ' . TOPICS_TABLE . ' t
                JOIN ' . POSTS_TABLE . ' p ON p.post_id = t.topic_first_post_id
                WHERE t.forum_id = ' . (int) $forum_id . '
                  AND t.topic_visibility = 1
                ORDER BY t.topic_time DESC';
        $result = $this->db->sql_query_limit($sql, $limit);

        $count = 0;
        while ($row = $this->db->sql_fetchrow($result)) {
            if (!$this->auth->acl_get('f_read', (int) $row['forum_id'])) {
                continue;
            }

            $this->template->assign_block_vars('news', [
                'TITLE'      => censor_text($row['topic_title']),
                'AUTHOR'     => get_username_string('full', (int) $row['topic_poster'], $row['topic_first_poster_name'], $row['topic_first_poster_colour']),
                'DATE'       => $this->user->format_date($row['topic_time']),
                'EXCERPT'    => $this->excerpt_from_post($row['post_text'], $row['bbcode_uid']),
                'U_TOPIC'    => append_sid("{$this->root_path}viewtopic.{$this->php_ext}", 'f=' . (int) $row['forum_id'] . '&amp;t=' . (int) $row['topic_id']),
            ]);
            $count++;
        }
        $this->db->sql_freeresult($result);

        $this->template->assign_var('S_NEWS_HAS_CONTENT', $count > 0);
    }

    protected function excerpt_from_post($text, $uid, $max_chars = 240)
    {
        strip_bbcode($text, $uid);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        if (mb_strlen($text) > $max_chars) {
            $text = mb_substr($text, 0, $max_chars - 1) . '…';
        }
        return $text;
    }

    protected function assign_recent_topics($limit)
    {
        $sql = 'SELECT t.topic_id, t.forum_id, t.topic_title, t.topic_time,
                       t.topic_last_post_time, (t.topic_posts_approved - 1) AS topic_replies, t.topic_views,
                       t.topic_last_poster_name, f.forum_name
                FROM ' . TOPICS_TABLE . ' t
                JOIN ' . FORUMS_TABLE . ' f ON f.forum_id = t.forum_id
                WHERE t.topic_visibility = 1
                  AND f.forum_type = ' . FORUM_POST . '
                ORDER BY t.topic_last_post_time DESC';
        $result = $this->db->sql_query_limit($sql, $limit);

        while ($row = $this->db->sql_fetchrow($result)) {
            if (!$this->auth->acl_get('f_read', (int) $row['forum_id'])) {
                continue;
            }

            $this->template->assign_block_vars('recent_topics', [
                'TITLE'       => censor_text($row['topic_title']),
                'FORUM_NAME'  => $row['forum_name'],
                'AUTHOR'      => $row['topic_last_poster_name'],
                'REPLIES'     => (int) $row['topic_replies'],
                'VIEWS'       => (int) $row['topic_views'],
                'LAST_POST'   => $this->user->format_date($row['topic_last_post_time']),
                'U_TOPIC'     => append_sid("{$this->root_path}viewtopic.{$this->php_ext}", 'f=' . (int) $row['forum_id'] . '&amp;t=' . (int) $row['topic_id']),
                'U_FORUM'     => append_sid("{$this->root_path}viewforum.{$this->php_ext}", 'f=' . (int) $row['forum_id']),
            ]);
        }
        $this->db->sql_freeresult($result);
    }

    protected function assign_popular_topics($limit)
    {
        $sql = 'SELECT t.topic_id, t.forum_id, t.topic_title,
                       (t.topic_posts_approved - 1) AS topic_replies, t.topic_views
                FROM ' . TOPICS_TABLE . ' t
                JOIN ' . FORUMS_TABLE . ' f ON f.forum_id = t.forum_id
                WHERE t.topic_visibility = 1
                  AND f.forum_type = ' . FORUM_POST . '
                ORDER BY t.topic_views DESC, (t.topic_posts_approved - 1) DESC';
        $result = $this->db->sql_query_limit($sql, $limit);

        while ($row = $this->db->sql_fetchrow($result)) {
            if (!$this->auth->acl_get('f_read', (int) $row['forum_id'])) {
                continue;
            }
            $this->template->assign_block_vars('popular_topics', [
                'TITLE'   => censor_text($row['topic_title']),
                'VIEWS'   => (int) $row['topic_views'],
                'REPLIES' => (int) $row['topic_replies'],
                'U_TOPIC' => append_sid("{$this->root_path}viewtopic.{$this->php_ext}", 'f=' . (int) $row['forum_id'] . '&amp;t=' . (int) $row['topic_id']),
            ]);
        }
        $this->db->sql_freeresult($result);
    }

    protected function assign_new_members($limit)
    {
        $sql = 'SELECT user_id, username, user_colour, user_regdate
                FROM ' . USERS_TABLE . '
                WHERE user_type IN (' . USER_NORMAL . ', ' . USER_FOUNDER . ')
                ORDER BY user_regdate DESC';
        $result = $this->db->sql_query_limit($sql, $limit);

        while ($row = $this->db->sql_fetchrow($result)) {
            $this->template->assign_block_vars('new_members', [
                'USERNAME' => get_username_string('full', $row['user_id'], $row['username'], $row['user_colour']),
                'JOINED'   => $this->user->format_date($row['user_regdate'], 'd M Y'),
            ]);
        }
        $this->db->sql_freeresult($result);
    }

    protected function assign_forums($limit)
    {
        $sql = 'SELECT forum_id, forum_name, forum_desc, forum_topics_approved, forum_posts_approved
                FROM ' . FORUMS_TABLE . '
                WHERE forum_type = ' . FORUM_POST . '
                ORDER BY left_id ASC';
        $result = $this->db->sql_query_limit($sql, $limit);

        while ($row = $this->db->sql_fetchrow($result)) {
            if (!$this->auth->acl_get('f_list', (int) $row['forum_id'])) {
                continue;
            }

            $this->template->assign_block_vars('portal_forums', [
                'NAME'        => $row['forum_name'],
                'DESCRIPTION' => generate_text_for_display($row['forum_desc'], '', '', 7),
                'TOPICS'      => (int) $row['forum_topics_approved'],
                'POSTS'       => (int) $row['forum_posts_approved'],
                'U_FORUM'     => append_sid("{$this->root_path}viewforum.{$this->php_ext}", 'f=' . (int) $row['forum_id']),
            ]);
        }
        $this->db->sql_freeresult($result);
    }
}
