<?php

declare(strict_types=1);

namespace app\widgets;

use yii\base\Widget;

/**
 * Test widget for unit testing the widget inspector.
 */
class TestWidget extends Widget
{
    /**
     * @var string Title displayed in the widget header
     */
    public $title = 'Default Title';

    /**
     * @var array CSS class options for the container
     */
    public $options = [];

    public const EVENT_CUSTOM_ACTION = 'customAction';

    /**
     * Renders the widget content.
     *
     * @return string
     */
    public function run(): string
    {
        return '<div>' . $this->title . '</div>';
    }
}
