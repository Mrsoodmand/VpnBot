<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class HeadTitleTest extends TestCase
{
    public function test_title_and_body_are_separated_by_a_visible_divider(): void
    {
        $message = headTitle(' عنوان آزمایشی ') . 'متن پیام';

        $this->assertSame("\u{200F}<b>عنوان آزمایشی</b>\n────────────────────────────────\nمتن پیام", $message);
    }

    public function test_rtl_marker_is_added_to_each_non_empty_line(): void
    {
        $message = rtlMessage("<b>عنوان</b>\n📦 متن\n\n<code>vip</code>");

        $this->assertSame(
            "\u{200F}<b>عنوان</b>\n\u{200F}📦 متن\n\n\u{200F}<code>vip</code>",
            $message
        );
    }
}
