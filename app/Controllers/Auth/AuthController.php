<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Models\User;
use Kayra\Auth\AuthManager;
use Kayra\Auth\Hasher;
use Kayra\Exceptions\ValidationException;
use Kayra\Http\Controller;
use Kayra\Http\Request;
use Kayra\RateLimiter\RateLimiter;
use Kayra\Session\Session;
use Kayra\Validation\Validator;
use Psr\Http\Message\ResponseInterface;

/**
 * Registration, login and logout.
 *
 * Worth noting in the login flow:
 *
 *  - A failed attempt says "credentials do not match" without saying which
 *    half was wrong, so the form cannot be used to discover valid addresses.
 *  - The route carries `throttle`, because a password form with no rate limit
 *    is a credential-stuffing endpoint. That limit is per client address, so
 *    login() adds a second one per account: without it, a thousand addresses
 *    each staying inside their own budget still add up to a thousand guesses
 *    against one password.
 *  - attempt() regenerates the session id, so a fixed session cannot survive
 *    the privilege change.
 */
final class AuthController extends Controller
{
    /**
     * Failed sign-ins allowed against one account before it stops answering,
     * and for how long.
     *
     * This is a deliberate trade: it also lets someone lock a known address out
     * for a quarter of an hour. Ten failures is loose enough that a person
     * mistyping does not trip it, and the window is short enough that being
     * locked out is an inconvenience rather than an outage.
     */
    private const ACCOUNT_ATTEMPTS = 10;

    private const ACCOUNT_WINDOW = 900;

    public function __construct(
        private readonly AuthManager $auth,
        private readonly Hasher $hasher,
        private readonly Session $session,
        private readonly RateLimiter $limiter,
    ) {
    }

    /* ------------------------------------------------------------ register */

    public function showRegister(): ResponseInterface
    {
        return $this->auth->check()
            ? $this->redirect('/posts')
            : $this->view('auth.register');
    }

    public function register(Request $request): ResponseInterface
    {
        try {
            // safe() hands back the same data as validate(), readable as the
            // types the rules just established -- no casting mixed and hoping.
            $input = Validator::make($request->all(), [
                'name'     => 'required|string|min:2|max:100',
                'email'    => 'required|email|max:255',
                // 72 bytes is bcrypt's hard limit; anything longer would be
                // silently truncated, so it is rejected at validation instead.
                'password' => 'required|string|min:8|max:72|confirmed',
            ])->safe();
        } catch (ValidationException $e) {
            return $this->back('/register', $e->errors, $request->only(['name', 'email']));
        }

        $name = $input->string('name');
        $email = $input->string('email');

        if (User::query()->where('email', $email)->exists()) {
            return $this->back(
                '/register',
                ['email' => ['That email address is already registered.']],
                ['name' => $name],
            );
        }

        $user = new User(['name' => $name, 'email' => $email]);
        $user->forceFill(['password' => $this->hasher->make($input->string('password'))]);
        $user->save();

        $this->auth->session()->login($user);
        $this->session->flash('status', 'Welcome, ' . $name . '.');

        return $this->redirect('/posts');
    }

    /* --------------------------------------------------------------- login */

    public function showLogin(): ResponseInterface
    {
        return $this->auth->check()
            ? $this->redirect('/posts')
            : $this->view('auth.login');
    }

    public function login(Request $request): ResponseInterface
    {
        try {
            $input = Validator::make($request->all(), [
                'email'    => 'required|email',
                'password' => 'required|string',
            ])->safe();
        } catch (ValidationException $e) {
            return $this->back('/login', $e->errors, $request->only(['email']));
        }

        $email = $input->string('email');
        $key = $this->throttleKey($email);

        if ($this->limiter->tooManyAttempts($key, self::ACCOUNT_ATTEMPTS)) {
            $minutes = (int) ceil($this->limiter->availableIn($key) / 60);

            return $this->back(
                '/login',
                ['email' => ["Too many sign-in attempts. Try again in {$minutes} minute(s)."]],
                ['email' => $email],
            );
        }

        $ok = $this->auth->session()->attempt([
            'email'    => $email,
            'password' => $input->string('password'),
        ]);

        if (!$ok) {
            // Counted whether or not the address exists, so the limiter cannot
            // be used to tell registered addresses from unregistered ones.
            $this->limiter->hit($key, self::ACCOUNT_WINDOW);

            // Deliberately vague, and attached to the form rather than a field:
            // saying "no such account" or "wrong password" tells an attacker
            // which addresses are registered.
            return $this->back(
                '/login',
                ['email' => ['Those credentials do not match our records.']],
                ['email' => $email],
            );
        }

        // A correct password ends the lockout: the failures were somebody
        // guessing, and the owner should not inherit their budget.
        $this->limiter->clear($key);

        $this->session->flash('status', 'Signed in.');

        return $this->redirect('/posts');
    }

    public function logout(): ResponseInterface
    {
        $this->auth->session()->logout();

        return $this->redirect('/login');
    }

    /* --------------------------------------------------------------- utils */

    /**
     * The per-account limiter key.
     *
     * Hashed, and lower-cased first so "Kawsar@x.com" and "kawsar@x.com" share
     * a budget. Hashing keeps the address out of the limiter's storage, which
     * would otherwise become a list of every address anyone has tried.
     */
    private function throttleKey(string $email): string
    {
        return 'login:' . hash('sha256', strtolower(trim($email)));
    }

    /**
     * Send the user back to a form with errors and their input.
     *
     * The destination is passed in rather than read from the Referer header.
     * That header is attacker-controlled, and redirecting to it would turn a
     * failed login into an open redirect; each action already knows which form
     * it came from.
     *
     * Flashed rather than rendered inline so the response is a redirect: a
     * refresh after a failed POST then re-runs the GET, not the POST.
     *
     * @param array<string, list<string>> $errors
     * @param array<string, mixed>        $old
     */
    private function back(string $to, array $errors, array $old = []): ResponseInterface
    {
        $this->session->flash('errors', $errors);
        $this->session->flash('old', $old);

        return $this->redirect($to);
    }
}
