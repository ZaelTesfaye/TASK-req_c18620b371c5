<?php
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\service\CleansingService;
use ReflectionClass;

/**
 * CleansingNormalizationTest - Tests deterministic cleansing normalization
 * via reflection on shipped CleansingService private methods.
 */
class CleansingNormalizationTest extends TestCase
{
    private static ReflectionClass $ref;

    public static function setUpBeforeClass(): void
    {
        self::$ref = new ReflectionClass(CleansingService::class);
    }

    private function invoke(string $method, array $args): mixed
    {
        $m = self::$ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs(null, $args);
    }

    // Job title normalization

    public function testJobTitleSeniorAbbreviation(): void
    {
        $this->assertEquals('Senior Developer', $this->invoke('normalizeJobTitle', ['Sr. Dev']));
    }

    public function testJobTitleJuniorAbbreviation(): void
    {
        $this->assertEquals('Junior Engineer', $this->invoke('normalizeJobTitle', ['Jr. Eng']));
    }

    public function testJobTitleSWEExpansion(): void
    {
        $this->assertEquals('Software Engineer', $this->invoke('normalizeJobTitle', ['SWE']));
    }

    public function testJobTitleWhitespaceNormalization(): void
    {
        $this->assertEquals('Software Engineer', $this->invoke('normalizeJobTitle', ['  software   engineer  ']));
    }

    public function testJobTitleManagerAbbreviation(): void
    {
        $this->assertStringContainsString('Manager', $this->invoke('normalizeJobTitle', ['Mgr']));
    }

    // Company normalization

    public function testCompanyNormalizationRemovesInc(): void
    {
        $this->assertEquals('Acme', $this->invoke('normalizeCompany', ['Acme Inc.']));
    }

    public function testCompanyNormalizationRemovesLLC(): void
    {
        $this->assertEquals('Tech Solutions', $this->invoke('normalizeCompany', ['Tech Solutions LLC']));
    }

    public function testCompanyNormalizationCaseNormalized(): void
    {
        $this->assertEquals('Google', $this->invoke('normalizeCompany', ['GOOGLE']));
    }

    public function testCompanyNormalizationRemovesCorp(): void
    {
        $this->assertEquals('Big', $this->invoke('normalizeCompany', ['Big Corp']));
    }

    // Salary normalization

    public function testSalaryWithKSuffix(): void
    {
        $this->assertEquals('75000', $this->invoke('normalizeSalary', ['$75k']));
    }

    public function testSalaryWithMSuffix(): void
    {
        $this->assertEquals('1500000', $this->invoke('normalizeSalary', ['1.5M']));
    }

    public function testSalaryNumericOnly(): void
    {
        $this->assertEquals('50000', $this->invoke('normalizeSalary', ['$50,000']));
    }

    public function testSalaryAlreadyClean(): void
    {
        $this->assertEquals('80000', $this->invoke('normalizeSalary', ['80000']));
    }

    // Education normalization

    public function testEducationBS(): void
    {
        $this->assertEquals("Bachelor's", $this->invoke('normalizeEducation', ['BS']));
    }

    public function testEducationMS(): void
    {
        $this->assertEquals("Master's", $this->invoke('normalizeEducation', ['MS']));
    }

    public function testEducationPhD(): void
    {
        $this->assertEquals('PhD', $this->invoke('normalizeEducation', ['PhD']));
    }

    public function testEducationHighSchool(): void
    {
        $this->assertEquals('High School', $this->invoke('normalizeEducation', ['hs']));
    }

    // Determinism

    public function testDeterministicOutput(): void
    {
        $result1 = $this->invoke('normalizeJobTitle', ['Sr. Dev at Acme Corp.']);
        $result2 = $this->invoke('normalizeJobTitle', ['Sr. Dev at Acme Corp.']);
        $this->assertEquals($result1, $result2);
    }

    // Full row normalization

    public function testNormalizeRowPipeline(): void
    {
        $row = [
            'job_title' => 'Jr. Eng',
            'company' => 'StartupCo Inc',
            'city' => '  san  francisco  ',
            'salary' => '$120k',
            'education' => 'MS',
            'experience' => '3-5 years',
        ];
        $result = $this->invoke('normalizeRow', [$row, 'customer_entered']);
        $this->assertEquals('Junior Engineer', $result['job_title']);
        $this->assertEquals('Startupco', $result['company']);
        $this->assertEquals('San Francisco', $result['city']);
        $this->assertEquals('120000', $result['salary']);
        $this->assertEquals("Master's", $result['education']);
        $this->assertEquals('3-5 years', $result['experience']);
    }
}
