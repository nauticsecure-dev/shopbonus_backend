<?php

namespace Tests\Unit\Integrations;

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class AdobeRuntimeActionTest extends TestCase
{
    public function test_private_runtime_action_protocol_and_security_regressions(): void
    {
        $config = Yaml::parseFile(base_path('merchant-packages/adobe-appbuilder/app.config.yaml'));
        $action = $config['application']['runtimeManifest']['packages']['shopbonus']['actions']['orders'];
        $this->assertSame('no', $action['web']);
        $this->assertTrue($action['annotations']['require-adobe-auth']);
        $p = new Process(['node', '--test', base_path('tests/NativeAdobe/action.test.js')], base_path());
        $p->setTimeout(30);
        $p->run();
        $this->assertTrue($p->isSuccessful(), $p->getOutput().$p->getErrorOutput());
    }
}
