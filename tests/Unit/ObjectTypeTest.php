<?php
/**
 * Test del riconoscimento dei tipi di oggetto con ID di un post.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Tests\Unit;

use Mavida\FiveW\Support\ObjectType;
use PHPUnit\Framework\TestCase;

/**
 * ObjectType::is_post().
 */
final class ObjectTypeTest extends TestCase {

	public function test_post_like_types_with_an_id(): void {
		foreach ( array( 'post', 'page', 'home', 'post_type_attachment' ) as $type ) {
			$this->assertTrue( ObjectType::is_post( $type, 5 ), $type );
		}
	}

	public function test_home_without_id_is_not_a_post(): void {
		$this->assertFalse( ObjectType::is_post( 'home', 0 ) );
	}

	public function test_other_types_are_not_posts(): void {
		foreach ( array( 'category', 'post_tag', 'tax_genere', 'author', 'archive', '404', 'search', 'loginpage' ) as $type ) {
			$this->assertFalse( ObjectType::is_post( $type, 5 ), $type );
		}
	}
}
