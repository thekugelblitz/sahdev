<?php
namespace App\Http\Controllers;
use App\Models\User;
use App\Services\OrganizationService;
use App\Support\Audit;
use App\Support\Totp;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
final class AuthController
{
    public function login(Request $r)
    {
        $v = $r->validate(['email'=>'required|email|max:254','password'=>'required|string|max:200']);
        $commandLogin = $r->is('command/login');
        if (!Auth::attempt(['email'=>strtolower($v['email']),'password'=>$v['password'],'is_operator'=>$commandLogin])) throw ValidationException::withMessages(['email'=>'The email or password is incorrect.']);
        $r->session()->regenerate();
        return $commandLogin ? redirect('/command') : redirect()->intended('/dashboard');
    }
    public function register(Request $r, OrganizationService $organizations)
    {
        abort_unless(config('cloud.registration'),403,'Registration is currently closed.');
        $v = $r->validate(['name'=>'required|string|max:100','organization'=>'required|string|max:120','email'=>'required|email|max:254','password'=>['required','confirmed','max:200',PasswordRule::min(12)->letters()->numbers()]]);
        $v['email'] = strtolower($v['email']);
        if (User::where('email',$v['email'])->exists()) throw ValidationException::withMessages(['email'=>'Unable to register with this email. Try signing in or resetting your password.']);
        [$user,$org] = DB::transaction(function () use ($v,$organizations) {
            $user = User::create(['name'=>$v['name'],'email'=>$v['email'],'password'=>$v['password']]);
            return [$user,$organizations->create($user,$v['organization'])];
        });
        Auth::login($user); $r->session()->regenerate(); $r->session()->put('organization_id',$org);
        event(new Registered($user));
        return redirect('/verify-email');
    }
    public function logout(Request $r)
    {
        Auth::logout(); $r->session()->invalidate(); $r->session()->regenerateToken(); return redirect('/login');
    }
    public function forgot(Request $r)
    {
        $r->validate(['email'=>'required|email|max:254']);
        Password::sendResetLink(['email'=>strtolower($r->input('email'))]);
        return back()->with('status','If this account exists, a reset link has been sent.');
    }
    public function reset(Request $r)
    {
        $v = $r->validate(['token'=>'required|string','email'=>'required|email','password'=>['required','confirmed','max:200',PasswordRule::min(12)->letters()->numbers()]]);
        $status = Password::reset($v,function (User $user,string $password) {
            $user->forceFill(['password'=>$password,'remember_token'=>Str::random(60)])->save();
            DB::table('sessions')->where('user_id',$user->id)->delete(); event(new PasswordReset($user));
        });
        if ($status !== Password::PasswordReset) throw ValidationException::withMessages(['email'=>__($status)]);
        return redirect('/login')->with('status','Password reset. Sign in again.');
    }
    public function security(Request $r)
    {
        abort_unless($r->user()->is_operator,403);
        if (!DB::table('operator_security')->where('id',1)->value('mfa_required')) return redirect('/command/settings');
        if (!$r->user()->totp_secret && !$r->session()->has('totp_enrollment')) $r->session()->put('totp_enrollment',Totp::secret());
        return view('security',['secret'=>$r->user()->totp_secret ? null : $r->session()->get('totp_enrollment')]);
    }
    public function verifyOperator(Request $r)
    {
        abort_unless($r->user()->is_operator,403);
        $policy = DB::table('operator_security')->find(1);
        abort_unless($policy?->mfa_required,403);
        $v = $r->validate(['password'=>'required|string','code'=>'required|string|size:6']);
        $step = DB::transaction(function () use ($r,$v) {
            $user = User::where('id',$r->user()->id)->lockForUpdate()->firstOrFail();
            $secret = $user->totp_secret ?: $r->session()->get('totp_enrollment');
            if (!$secret || !Hash::check($v['password'],$user->password) || !($step = Totp::verify($secret,$v['code'],$user->totp_last_step))) throw ValidationException::withMessages(['code'=>'Password or authenticator code is invalid or already used.']);
            $user->forceFill(['totp_secret'=>$secret,'totp_last_step'=>$step])->save();
            Audit::record('operator.verified',$user->id);
            return $step;
        });
        $r->session()->forget('totp_enrollment'); $r->session()->regenerate(); $r->session()->put('operator_verified_at',time());
        $r->session()->put('operator_mfa_revision',$policy->revision);
        return redirect('/command');
    }

    public function operatorSettings()
    {
        return view('operator-settings', ['required'=>DB::table('operator_security')->where('id',1)->value('mfa_required')]);
    }

    public function saveOperatorSettings(Request $r)
    {
        $settings = $r->validate(['mfa_required'=>'required|boolean','password'=>'required|current_password']);
        $required = (bool) $settings['mfa_required'];
        DB::transaction(function () use ($r,$required) {
            $policy = DB::table('operator_security')->lockForUpdate()->find(1);
            if ((bool) $policy->mfa_required === $required) return;
            DB::table('operator_security')->where('id',1)->update([
                'mfa_required'=>$required,
                'revision'=>(string) Str::uuid(),
            ]);
            Audit::record('operator.mfa_policy_changed',$r->user()->id,null,null,['required'=>$required]);
        });
        return redirect('/command')->with('status',$required ? 'Operator MFA enabled.' : 'Operator MFA disabled.');
    }
}
