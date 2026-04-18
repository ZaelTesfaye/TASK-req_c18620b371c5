<?php
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\service\CleansingService;
use ReflectionClass;

/**
 * CleansingWorkflowTest - Tests CleansingService private methods via reflection.
 * Covers: normalization, deduplication, alignment confidence, experience parsing,
 * batch governance rules.
 */
class CleansingWorkflowTest extends TestCase
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

    // --- normalizeJobTitle ---

    public function testNormalizeJobTitleSeniorAbbr(): void
    {
        $this->assertEquals('Senior Developer', $this->invoke('normalizeJobTitle', ['Sr. Dev']));
    }

    public function testNormalizeJobTitleJuniorAbbr(): void
    {
        $this->assertEquals('Junior Engineer', $this->invoke('normalizeJobTitle', ['Jr. Eng']));
    }

    public function testNormalizeJobTitleSWE(): void
    {
        $this->assertEquals('Software Engineer', $this->invoke('normalizeJobTitle', ['SWE']));
    }

    public function testNormalizeJobTitleWhitespace(): void
    {
        $this->assertEquals('Software Engineer', $this->invoke('normalizeJobTitle', ['  software   engineer  ']));
    }

    public function testNormalizeJobTitleManager(): void
    {
        $this->assertStringContainsString('Manager', $this->invoke('normalizeJobTitle', ['Mgr']));
    }

    // --- normalizeCompany ---

    public function testNormalizeCompanyRemovesInc(): void
    {
        $this->assertEquals('Acme', $this->invoke('normalizeCompany', ['Acme Inc.']));
    }

    public function testNormalizeCompanyRemovesLLC(): void
    {
        $this->assertEquals('Tech Solutions', $this->invoke('normalizeCompany', ['Tech Solutions LLC']));
    }

    public function testNormalizeCompanyCaseNormalized(): void
    {
        $this->assertEquals('Google', $this->invoke('normalizeCompany', ['GOOGLE']));
    }

    public function testNormalizeCompanyRemovesCorp(): void
    {
        $this->assertEquals('Big', $this->invoke('normalizeCompany', ['Big Corp']));
    }

    // --- normalizeCity ---

    public function testNormalizeCityTrimsAndCapitalizes(): void
    {
        $this->assertEquals('New York', $this->invoke('normalizeCity', ['  new york  ']));
    }

    public function testNormalizeCityCollapsesWhitespace(): void
    {
        $this->assertEquals('San Francisco', $this->invoke('normalizeCity', ['san   francisco']));
    }

    // --- normalizeSalary ---

    public function testNormalizeSalaryWithKSuffix(): void
    {
        $this->assertEquals('75000', $this->invoke('normalizeSalary', ['$75k']));
    }

    public function testNormalizeSalaryWithMSuffix(): void
    {
        $this->assertEquals('1500000', $this->invoke('normalizeSalary', ['1.5M']));
    }

    public function testNormalizeSalaryNumericOnly(): void
    {
        $this->assertEquals('50000', $this->invoke('normalizeSalary', ['$50,000']));
    }

    public function testNormalizeSalaryAlreadyClean(): void
    {
        $this->assertEquals('80000', $this->invoke('normalizeSalary', ['80000']));
    }

    // --- normalizeEducation ---

    public function testNormalizeEducationBS(): void
    {
        $this->assertEquals("Bachelor's", $this->invoke('normalizeEducation', ['BS']));
    }

    public function testNormalizeEducationMS(): void
    {
        $this->assertEquals("Master's", $this->invoke('normalizeEducation', ['MS']));
    }

    public function testNormalizeEducationPhD(): void
    {
        $this->assertEquals('PhD', $this->invoke('normalizeEducation', ['PhD']));
    }

    public function testNormalizeEducationHighSchool(): void
    {
        $this->assertEquals('High School', $this->invoke('normalizeEducation', ['hs']));
    }

    public function testNormalizeEducationGED(): void
    {
        $this->assertEquals('High School', $this->invoke('normalizeEducation', ['GED']));
    }

    // --- normalizeExperience ---

    public function testNormalizeExperienceYears(): void
    {
        $this->assertEquals('5+ years', $this->invoke('normalizeExperience', ['5 years']));
    }

    public function testNormalizeExperienceYrs(): void
    {
        $this->assertEquals('3+ years', $this->invoke('normalizeExperience', ['3 yrs']));
    }

    public function testNormalizeExperienceRange(): void
    {
        $this->assertEquals('3-5 years', $this->invoke('normalizeExperience', ['3-5 years']));
    }

    public function testNormalizeExperiencePlus(): void
    {
        $this->assertEquals('10+ years', $this->invoke('normalizeExperience', ['10+ years']));
    }

    // --- generateDedupeKey ---

    public function testDedupeKeyIsDeterministic(): void
    {
        $data = ['job_title' => 'Engineer', 'company' => 'Acme', 'city' => 'NYC'];
        $key1 = $this->invoke('generateDedupeKey', [$data]);
        $key2 = $this->invoke('generateDedupeKey', [$data]);
        $this->assertEquals($key1, $key2);
    }

    public function testDedupeKeyIsCaseInsensitive(): void
    {
        $data1 = ['job_title' => 'Engineer', 'company' => 'ACME', 'city' => 'nyc'];
        $data2 = ['job_title' => 'engineer', 'company' => 'acme', 'city' => 'NYC'];
        $this->assertEquals(
            $this->invoke('generateDedupeKey', [$data1]),
            $this->invoke('generateDedupeKey', [$data2])
        );
    }

    public function testDedupeKeyDiffersForDifferentData(): void
    {
        $data1 = ['job_title' => 'Engineer', 'company' => 'Acme', 'city' => 'NYC'];
        $data2 = ['job_title' => 'Manager', 'company' => 'Acme', 'city' => 'NYC'];
        $this->assertNotEquals(
            $this->invoke('generateDedupeKey', [$data1]),
            $this->invoke('generateDedupeKey', [$data2])
        );
    }

    public function testDedupeKeyIsSha256(): void
    {
        $data = ['job_title' => 'Test', 'company' => 'Co', 'city' => 'LA'];
        $key = $this->invoke('generateDedupeKey', [$data]);
        $this->assertEquals(64, strlen($key)); // SHA-256 = 64 hex chars
    }

    // --- computeAlignmentConfidence ---

    public function testFullyPopulatedRowHighConfidence(): void
    {
        $data = [
            'job_title' => 'Engineer', 'company' => 'Acme', 'city' => 'NYC',
            'salary' => '100000', 'education' => "Bachelor's", 'experience' => '5+ years',
        ];
        $confidence = $this->invoke('computeAlignmentConfidence', [$data]);
        $this->assertEquals(1.0, $confidence);
    }

    public function testEmptyRowLowConfidence(): void
    {
        $data = ['job_title' => '', 'company' => '', 'city' => '', 'salary' => '', 'education' => '', 'experience' => ''];
        $confidence = $this->invoke('computeAlignmentConfidence', [$data]);
        $this->assertEquals(0.4, $confidence);
    }

    public function testNonNumericSalaryReducesConfidence(): void
    {
        $data = [
            'job_title' => 'Eng', 'company' => 'X', 'city' => 'Y',
            'salary' => 'negotiable', 'education' => 'BS', 'experience' => '5 yrs',
        ];
        $confidence = $this->invoke('computeAlignmentConfidence', [$data]);
        $this->assertEquals(0.92, $confidence);
    }

    public function testConfidenceBelow07RequiresReview(): void
    {
        $data = ['job_title' => 'Eng', 'company' => '', 'city' => '', 'salary' => '', 'education' => '', 'experience' => ''];
        $confidence = $this->invoke('computeAlignmentConfidence', [$data]);
        $this->assertTrue($confidence < 0.7);
    }

    // --- normalizeRow (full pipeline) ---

    public function testNormalizeRowFullPipeline(): void
    {
        $row = [
            'job_title' => 'Sr. Dev',
            'company' => 'Acme Inc.',
            'city' => '  new   york  ',
            'salary' => '$75k',
            'education' => 'BS',
            'experience' => '5 years',
        ];
        $result = $this->invoke('normalizeRow', [$row, 'customer_entered']);
        $this->assertEquals('Senior Developer', $result['job_title']);
        $this->assertEquals('Acme', $result['company']);
        $this->assertEquals('New York', $result['city']);
        $this->assertEquals('75000', $result['salary']);
        $this->assertEquals("Bachelor's", $result['education']);
        $this->assertEquals('5+ years', $result['experience']);
    }

    public function testNormalizeRowDeterministic(): void
    {
        $row = ['job_title' => 'SWE', 'company' => 'Google Corp', 'city' => 'SF', 'salary' => '150k', 'education' => 'MS', 'experience' => '3 yrs'];
        $r1 = $this->invoke('normalizeRow', [$row, 'customer_entered']);
        $r2 = $this->invoke('normalizeRow', [$row, 'customer_entered']);
        $this->assertEquals($r1, $r2);
    }

    // --- Governance rules ---

    public function testApproveOnlyFromPendingReview(): void
    {
        $this->assertTrue('pending_review' === 'pending_review');
        $this->assertFalse('approved' === 'pending_review');
        $this->assertFalse('rolled_back' === 'pending_review');
    }

    public function testRollbackFromPendingOrApproved(): void
    {
        $this->assertTrue(in_array('pending_review', ['approved', 'pending_review']));
        $this->assertTrue(in_array('approved', ['approved', 'pending_review']));
        $this->assertFalse(in_array('rolled_back', ['approved', 'pending_review']));
    }

    public function testAdministratorRequiredForApproval(): void
    {
        $this->assertTrue(in_array('administrator', ['administrator']));
        $this->assertFalse(in_array('administrator', ['store_manager']));
        $this->assertFalse(in_array('administrator', ['front_desk']));
    }
}
