<?php

namespace Tests\Feature;

use App\Diagnostics\Catalog;
use App\Diagnostics\MtrReport;
use App\Models\Skill;
use Illuminate\Validation\ValidationException;
use Tests\IdentityTestCase;

class OperationalSkillsTest extends IdentityTestCase
{
    private function report(int $sent = 10, float $middle = 50, float $last = 0, string $destination = '8.8.8.8'): string
    {
        $make = fn ($n, $ip, $loss) => ['count' => $n, 'host' => $ip, 'Loss%' => $loss, 'Snt' => $sent, 'Avg' => 3, 'Best' => 2, 'Wrst' => 4, 'StDev' => 1];

        return json_encode(['report' => ['mtr' => ['protocol' => 'TCP'], 'hubs' => [$make(1, '192.168.1.1', $middle), $make(2, $destination, $last)]]]);
    }

    public function test_prepared_skills_are_closed_disabled_and_do_not_change_current_profiles(): void
    {
        $this->context()->forUser($this->admin);
        app(Catalog::class)->ensure();
        $this->assertSame(9, Skill::count());
        foreach (['mtr', 'tcpdump', 'nmap'] as $key) {
            $this->context()->forUser($this->admin);
            $s = Skill::where('key', $key)->sole();
            $this->assertFalse($s->enabled);
            $this->actingAs($this->admin)->put('/diagnostic-catalog/skills/'.$s->id, ['enabled' => 1])->assertConflict();
        }
        $this->get('/skills')->assertOk()->assertSee('executor indisponível');
        $this->get('/skills/guides/mtr')->assertOk()->assertSee('Analisar um relatório MTR existente')->assertSee('execução remota indisponível');
    }

    public function test_mtr_interpretation_does_not_attribute_intermediate_loss_to_transit(): void
    {
        $v = app(MtrReport::class)->analyze($this->report(), '8.8.8.8');
        $this->assertSame('SEM_PERDA_OBSERVADA', $v['state']);
        $this->assertSame('tcp', $v['protocol']);
        $badMetadata = json_decode($this->report(), true);
        $badMetadata['report']['mtr']['protocol'] = [];
        $this->assertSame('não informado', app(MtrReport::class)->analyze(json_encode($badMetadata), '8.8.8.8')['protocol']);
        $this->assertStringContainsString('não persistiu', implode(' ', $v['notes']));
        $this->assertStringContainsString('Amostra curta', implode(' ', $v['notes']));
        $v = app(MtrReport::class)->analyze($this->report(100, 5, 5), '8.8.8.8');
        $this->assertSame('INDICIO_DE_FALHA', $v['state']);
        $v = app(MtrReport::class)->analyze($this->report(10, 0, 0, '1.1.1.1'), '8.8.8.8');
        $this->assertSame('INCONCLUSIVO', $v['state']);
        $this->assertFalse($v['reached']);
    }

    public function test_bad_mtr_json_hostnames_and_inconsistent_numbers_are_rejected(): void
    {
        foreach (['not-json', $this->report(0), $this->report(10, 101), $this->report(10, 0, 0, '<script>')] as $report) {
            try {
                app(MtrReport::class)->analyze($report, '8.8.8.8');
                $this->fail('invalid_report_accepted');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_analysis_requires_admin_and_does_not_enable_network_execution(): void
    {
        $payload = ['target_ip' => '8.8.8.8', 'report' => $this->report()];
        $this->actingAs($this->operator)->post('/skills/guides/mtr/analysis', $payload)->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->admin)->post('/skills/guides/mtr/analysis', $payload)->assertOk()->assertSee('SEM_PERDA_OBSERVADA')->assertSee('não persistiu');
    }
}
