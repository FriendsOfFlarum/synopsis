<?php

/*
 * This file is part of fof/synopsis.
 *
 * (c) FriendsOfFlarum
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FoF\Synopsis;

use Flarum\Api\Controller\AbstractSerializeController;
use Flarum\Settings\SettingsRepositoryInterface;

class LoadRelations
{
    /**
     * @var SettingsRepositoryInterface
     */
    protected $settings;

    public function __construct(SettingsRepositoryInterface $settings)
    {
        $this->settings = $settings;
    }

    public function __invoke(AbstractSerializeController $controller)
    {
        // Only include the `lastPost` relation if we need it.
        if ($this->settings->get('fof-synopsis.excerpt-type') === 'last') {
            $controller->addInclude('lastPost');
        } else {
            $controller->addInclude('firstPost');
        }
    }
}
