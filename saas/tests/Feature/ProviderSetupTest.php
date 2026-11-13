<?php
namespace Tests\Feature;

use App\Services\ProviderEndpoint;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProviderSetupTest extends TestCase
{
    public function test_base_urls_are_normalized_without_accepting_unapproved_endpoints(): void
    {
        foreach ([
            ['https://api.openai.com/v1/','openai_compatible','https://api.openai.com/v1/chat/completions'],
            ['https://openrouter.ai','openai_compatible','https://openrouter.ai/api/v1/chat/completions'],
            ['https://api.anthropic.com/v1','anthropic','https://api.anthropic.com/v1/messages'],
            ['https://generativelanguage.googleapis.com/v1beta/openai','openai_compatible','https://generativelanguage.googleapis.com/v1beta/openai/chat/completions'],
        ] as [$url,$driver,$expected]) $this->assertSame($expected,ProviderEndpoint::normalize($url,$driver));
        $this->owner->forceFill(['is_operator'=>true])->save();
        $values = ['name'=>'OpenAI','driver'=>'openai_compatible','endpoint'=>'https://api.openai.com/v1','secret'=>'fixture-provider-secret','active'=>1,'_catalog'=>'providers:'];
        $this->actingAs($this->owner)->post('/command/catalog/providers',$values)->assertRedirectContains('/command?create=models&provider=');
        $provider = DB::table('providers')->where('name','OpenAI')->first();
        $this->assertSame('https://api.openai.com/v1/chat/completions',$provider->endpoint);
        $this->get('/command?create=models&provider='.$provider->id)->assertOk()->assertSee('Provider model identifier')->assertSee('gpt-5-nano')->assertDontSee('fixture-provider-secret');
    }
    public function test_invalid_endpoints_return_form_errors_without_flashing_credentials(): void
    {
        $this->owner->forceFill(['is_operator'=>true])->save();
        foreach (['http://127.0.0.1/v1','https://evil.example/v1','https://api.openai.com/v1/responses','https://api.openai.com/v1?api_key=secret'] as $url) {
            $this->actingAs($this->owner)->from('/command')->post('/command/catalog/providers',['name'=>'Invalid','driver'=>'openai_compatible','endpoint'=>$url,'secret'=>'fixture-private-key','active'=>1,'_catalog'=>'providers:'])
                ->assertRedirect('/command')->assertSessionHasErrors('endpoint')->assertSessionMissing('_old_input.secret');
        }
        $this->get('/command')->assertOk()->assertSee('Invalid')->assertDontSee('fixture-private-key');
        $this->assertDatabaseMissing('providers',['name'=>'Invalid']);
    }
}
