(() => {
  const form = document.querySelector('#registerForm');
  if (!form) return;

  const message = document.querySelector('#registerMessage');
  const submit = document.querySelector('#registerSubmit');

  document.querySelectorAll('[data-password-toggle]').forEach(toggle => {
    toggle.addEventListener('click', () => {
      const input = document.getElementById(toggle.dataset.passwordToggle);
      if (!input) return;

      const showingPassword = input.type === 'text';
      input.type = showingPassword ? 'password' : 'text';
      toggle.setAttribute('aria-label', showingPassword ? 'Show password' : 'Hide password');
      toggle.setAttribute('aria-pressed', String(!showingPassword));
      const icon = toggle.querySelector('i');
      if (icon) {
        icon.classList.toggle('fa-eye', showingPassword);
        icon.classList.toggle('fa-eye-slash', !showingPassword);
      }
    });
  });

  form.addEventListener('input', () => {
    message.textContent = '';
  });

  form.addEventListener('submit', async event => {
    event.preventDefault();
    message.textContent = '';
    const data = new FormData(form);
    const password = data.get('password') || '';
    if (password.length < 8 || password.length > 72 || !/[A-Za-z]/.test(password) || !/[0-9]/.test(password)) {
      message.textContent = 'Password must be 8–72 characters with at least one letter and one number.';
      return;
    }
    if (data.get('password') !== data.get('confirmPassword')) {
      message.textContent = 'Passwords do not match.';
      return;
    }

    submit.disabled = true;
    try {
      const response = await fetch('api/student/register.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          studentId: data.get('studentId'),
          program: data.get('program'),
          yearLevel: data.get('yearLevel'),
          fullName: data.get('fullName'),
          email: data.get('email'),
          phone: data.get('phone'),
          password: data.get('password'),
          confirmPassword: data.get('confirmPassword')
        })
      });
      const result = await response.json();
      if (!response.ok || !result.success) {
        message.textContent = result.message || 'Account registration could not be completed.';
        return;
      }
      message.textContent = result.message;
      form.reset();
      window.setTimeout(() => { window.location.href = 'login.html'; }, 1800);
    } catch (error) {
      message.textContent = 'The registration service is unavailable. Please try again.';
    } finally {
      submit.disabled = false;
    }
  });
})();
