<?php

namespace Lullabot\Mpx\Tests\Unit\DataService;

use GuzzleHttp\Psr7\Uri;
use Lullabot\Mpx\DataService\ResolveDomainResponseExtractor;
use Lullabot\Mpx\Service\AccessManagement\ResolveDomainResponse;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyInfo\Type;

/**
 * Tests mapping resolve domain array responses to strings and URIs.
 *
 * @coversDefaultClass \Lullabot\Mpx\DataService\ResolveDomainResponseExtractor
 */
class ResolveDomainResponseExtractorTest extends TestCase
{

    /**
     * @covers ::getTypes
     */
    public function testGetTypes()
    {
        $extractor = new ResolveDomainResponseExtractor();
        $types = $extractor->getTypes(ResolveDomainResponse::class, 'resolveDomainResponse');
        $this->assertCount(1, $types);
        /** @var \Symfony\Component\PropertyInfo\Type $type */
        $type = $types[0];
        $this->assertEquals(Type::BUILTIN_TYPE_ARRAY, $type->getBuiltinType());
        $this->assertEquals(Type::BUILTIN_TYPE_STRING, $type->getCollectionKeyType()->getBuiltinType());
        $this->assertEquals('object', $type->getCollectionValueType()->getBuiltinType());
        $this->assertEquals(Uri::class, $type->getCollectionValueType()->getClassName());
    }
}
