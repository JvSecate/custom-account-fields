(() => {
  const forms = Array.from(document.querySelectorAll('form.woocommerce-form-register, form.woocommerce-EditAccountForm'));

  if (!forms.length) {
    return;
  }

  const messages = {
    required: 'Campo obrigatório.',
    email: 'Informe um e-mail válido.',
    url: 'Informe uma URL válida.',
    tel: 'Informe um telefone válido.',
    number: 'Informe um número válido.',
    date: 'Informe uma data válida.',
    cpf: 'Informe um CPF válido.',
    cnpj: 'Informe um CNPJ válido.',
    cpf_cnpj: 'Informe um CPF ou CNPJ válido.',
    cep: 'Informe um CEP válido.',
    custom: 'Formato inválido.',
    invalid: 'Verifique este campo.',
    ...(window.customAccountFieldsValidation?.messages || {}),
  };

  const onlyDigits = (value) => value.replace(/\D/g, '');

  const validateCpf = (value) => {
    const digits = onlyDigits(value);

    if (!/^\d{11}$/.test(digits) || /^(\d)\1{10}$/.test(digits)) {
      return false;
    }

    let sum = 0;
    for (let i = 0; i < 9; i += 1) {
      sum += Number(digits[i]) * (10 - i);
    }

    let remainder = sum % 11;
    const firstDigit = remainder < 2 ? 0 : 11 - remainder;

    if (Number(digits[9]) !== firstDigit) {
      return false;
    }

    sum = 0;
    for (let i = 0; i < 10; i += 1) {
      sum += Number(digits[i]) * (11 - i);
    }

    remainder = sum % 11;
    const secondDigit = remainder < 2 ? 0 : 11 - remainder;

    return Number(digits[10]) === secondDigit;
  };

  const validateCnpj = (value) => {
    const digits = onlyDigits(value);

    if (!/^\d{14}$/.test(digits) || /^(\d)\1{13}$/.test(digits)) {
      return false;
    }

    const calculateDigit = (length) => {
      let sum = 0;
      let position = length - 7;

      for (let i = length; i >= 1; i -= 1) {
        sum += Number(digits[length - i]) * position;
        position -= 1;

        if (position < 2) {
          position = 9;
        }
      }

      const remainder = sum % 11;
      return remainder < 2 ? 0 : 11 - remainder;
    };

    return Number(digits[12]) === calculateDigit(12) && Number(digits[13]) === calculateDigit(13);
  };

  const compileRegex = (raw) => {
    if (!raw) {
      return null;
    }

    try {
      const match = raw.match(/^\/(.*)\/([a-z]*)$/i);
      return match ? new RegExp(match[1], match[2]) : new RegExp(raw);
    } catch (error) {
      return null;
    }
  };

  const getField = (input) => input.closest('.form-row') || input.closest('p, div, td') || input.parentElement;

  const getErrorElement = (field) => {
    let error = field.querySelector('.custom-account-fields-error');

    if (!error) {
      error = document.createElement('span');
      error.className = 'custom-account-fields-error';
      error.setAttribute('aria-live', 'polite');
      field.appendChild(error);
    }

    return error;
  };

  const setError = (field, input, message) => {
    const error = getErrorElement(field);

    if (!error.id) {
      error.id = `${input.id || input.name || 'caf-field'}-error`;
    }

    error.textContent = message;
    field.classList.add('custom-account-fields-invalid');
    input.setAttribute('aria-invalid', 'true');
    input.setAttribute('aria-describedby', error.id);
  };

  const clearError = (field, input) => {
    const error = field.querySelector('.custom-account-fields-error');

    if (error) {
      error.textContent = '';
    }

    field.classList.remove('custom-account-fields-invalid');
    input.removeAttribute('aria-invalid');
    input.removeAttribute('aria-describedby');
  };

  const hasValue = (input) => {
    if (input.type === 'checkbox') {
      return input.checked;
    }

    return input.value.trim() !== '';
  };

  const customMessage = (input, fallback) => input.getAttribute('data-caf-validation-message') || fallback;

  const validatePluginRule = (input) => {
    const value = input.type === 'checkbox' ? (input.checked ? '1' : '') : input.value.trim();
    const rule = input.getAttribute('data-caf-validation') || '';

    if (!rule || value === '') {
      return '';
    }

    if (rule === 'phone_br' && !/^\d{10,13}$/.test(onlyDigits(value))) {
      return customMessage(input, messages.tel);
    }

    if (rule === 'cpf' && !validateCpf(value)) {
      return customMessage(input, messages.cpf);
    }

    if (rule === 'cnpj' && !validateCnpj(value)) {
      return customMessage(input, messages.cnpj);
    }

    if (rule === 'cpf_cnpj' && !validateCpf(value) && !validateCnpj(value)) {
      return customMessage(input, messages.cpf_cnpj);
    }

    if (rule === 'email' && !/^\S+@\S+\.\S+$/.test(value)) {
      return customMessage(input, messages.email);
    }

    if (rule === 'url') {
      try {
        new URL(value);
      } catch (error) {
        return customMessage(input, messages.url);
      }
    }

    if (rule === 'cep' && !/^\d{8}$/.test(onlyDigits(value))) {
      return customMessage(input, messages.cep);
    }

    if (rule === 'custom') {
      const rawRegex = input.getAttribute('data-caf-validation-regex') || '';

      if (rawRegex === '') {
        return '';
      }

      const regex = compileRegex(rawRegex);
      if (!regex || !regex.test(value)) {
        return customMessage(input, messages.custom);
      }
    }

    return '';
  };

  const validateInput = (input, force = false) => {
    const field = getField(input);

    if (!field || input.disabled) {
      return true;
    }

    if (!force && input.dataset.cafTouched !== '1') {
      return true;
    }

    let message = '';

    if (input.required && !hasValue(input)) {
      message = messages.required;
    } else if (hasValue(input)) {
      const type = (input.getAttribute('type') || input.tagName).toLowerCase();

      if (type === 'email' && !input.validity.valid) {
        message = messages.email;
      } else if (type === 'url' && !input.validity.valid) {
        message = messages.url;
      } else if (type === 'number' && !input.validity.valid) {
        message = messages.number;
      } else if (type === 'date' && !input.validity.valid) {
        message = messages.date;
      } else if (input.hasAttribute('data-caf-field')) {
        message = validatePluginRule(input);
      } else if (!input.validity.valid) {
        message = input.validationMessage || messages.invalid;
      }
    }

    if (message) {
      setError(field, input, message);
      return false;
    }

    clearError(field, input);
    return true;
  };

  const getInputs = (form) => Array.from(form.querySelectorAll('input, select, textarea')).filter((input) => {
    const type = (input.getAttribute('type') || '').toLowerCase();
    return !['hidden', 'submit', 'button', 'reset'].includes(type);
  });

  forms.forEach((form) => {
    form.noValidate = true;

    getInputs(form).forEach((input) => {
      input.addEventListener('blur', () => {
        input.dataset.cafTouched = '1';
        validateInput(input);
      });

      input.addEventListener('input', () => {
        validateInput(input);
      });

      input.addEventListener('change', () => {
        input.dataset.cafTouched = '1';
        validateInput(input);
      });
    });

    form.addEventListener('submit', (event) => {
      const inputs = getInputs(form);
      const firstInvalid = inputs.find((input) => {
        input.dataset.cafTouched = '1';
        return !validateInput(input, true);
      });

      if (firstInvalid) {
        event.preventDefault();
        firstInvalid.focus({ preventScroll: true });
        firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    });
  });
})();
