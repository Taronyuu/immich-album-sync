<?php

namespace Tests\Unit;

use App\Filament\Resources\Albums\Schemas\AlbumForm;
use PHPUnit\Framework\TestCase;

class ParseImmichShareUrlTest extends TestCase
{
    public function test_parses_demo_immich_url(): void
    {
        $result = AlbumForm::parseImmichShareUrl('https://demo.immich.app/share/g-uFvWJPWktRQaW1PpK3W266c4aIPlsOcaGtzh8uiQtx1YOLkf5KlgzcZ2pFMMn3b-8');

        $this->assertSame('https://demo.immich.app', $result['base_url']);
        $this->assertSame('g-uFvWJPWktRQaW1PpK3W266c4aIPlsOcaGtzh8uiQtx1YOLkf5KlgzcZ2pFMMn3b-8', $result['share_key']);
    }

    public function test_handles_custom_port(): void
    {
        $result = AlbumForm::parseImmichShareUrl('http://immich.local:2283/share/abc123');

        $this->assertSame('http://immich.local:2283', $result['base_url']);
        $this->assertSame('abc123', $result['share_key']);
    }

    public function test_handles_trailing_slash(): void
    {
        $result = AlbumForm::parseImmichShareUrl('https://immich.example.com/share/abc123/');

        $this->assertSame('abc123', $result['share_key']);
    }

    public function test_returns_null_for_plain_key(): void
    {
        $this->assertNull(AlbumForm::parseImmichShareUrl('abc123'));
    }

    public function test_returns_null_for_url_without_share_segment(): void
    {
        $this->assertNull(AlbumForm::parseImmichShareUrl('https://immich.example.com/photos/abc'));
    }

    public function test_returns_null_for_empty(): void
    {
        $this->assertNull(AlbumForm::parseImmichShareUrl(''));
        $this->assertNull(AlbumForm::parseImmichShareUrl(null));
    }
}
