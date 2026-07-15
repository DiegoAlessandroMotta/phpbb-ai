<?php
namespace comunidad\portal;

class ext extends \phpbb\extension\base
{
    public function is_enableable()
    {
        $config = $this->container->get('config');
        return phpbb_version_compare($config['version'], '3.3.0', '>=');
    }
}
