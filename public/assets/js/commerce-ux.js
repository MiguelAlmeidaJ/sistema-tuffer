(() => {
    const digits = value => String(value ?? '').replace(/\D+/g, '');

    const formatCpf = value => {
        const raw = digits(value).slice(0, 11);
        if (raw.length <= 3) return raw;
        if (raw.length <= 6) return `${raw.slice(0, 3)}.${raw.slice(3)}`;
        if (raw.length <= 9) return `${raw.slice(0, 3)}.${raw.slice(3, 6)}.${raw.slice(6)}`;
        return `${raw.slice(0, 3)}.${raw.slice(3, 6)}.${raw.slice(6, 9)}-${raw.slice(9)}`;
    };

    const formatPhone = value => {
        const raw = digits(value).slice(0, 11);
        if (raw.length <= 2) return raw;
        if (raw.length <= 6) return `(${raw.slice(0, 2)}) ${raw.slice(2)}`;
        if (raw.length <= 10) return `(${raw.slice(0, 2)}) ${raw.slice(2, 6)}-${raw.slice(6)}`;
        return `(${raw.slice(0, 2)}) ${raw.slice(2, 7)}-${raw.slice(7)}`;
    };

    const formatCep = value => {
        const raw = digits(value).slice(0, 8);
        return raw.length > 5 ? `${raw.slice(0, 5)}-${raw.slice(5)}` : raw;
    };

    document.querySelectorAll('[data-cpf-input]').forEach(field => {
        const sync = () => {
            field.value = formatCpf(field.value);
        };
        field.addEventListener('input', sync);
        sync();
    });

    document.querySelectorAll('[data-phone-input]').forEach(field => {
        const sync = () => {
            field.value = formatPhone(field.value);
        };
        field.addEventListener('input', sync);
        sync();
    });

    document.querySelectorAll('[data-address-form]').forEach(form => {
        const cepField = form.querySelector('[data-address-cep]');
        const status = form.querySelector('[data-address-cep-status]');
        const street = form.querySelector('[data-address-street]');
        const neighborhood = form.querySelector('[data-address-neighborhood]');
        const city = form.querySelector('[data-address-city]');
        const state = form.querySelector('[data-address-state]');
        const ibge = form.querySelector('[data-address-ibge]');
        const number = form.querySelector('[data-address-number]');
        if (!cepField) return;

        let lastLookup = '';
        let controller = null;

        const setStatus = (message, stateName = '') => {
            if (!status) return;
            status.textContent = message;
            status.classList.remove('is-loading', 'is-success', 'is-error');
            if (stateName) status.classList.add(`is-${stateName}`);
        };

        const lookup = async () => {
            const cep = digits(cepField.value);
            cepField.value = formatCep(cep);

            if (cep.length !== 8) {
                lastLookup = '';
                setStatus('Digite os 8 números do CEP para preencher o endereço.');
                return;
            }
            if (cep === lastLookup) return;

            controller?.abort();
            controller = new AbortController();
            setStatus('Buscando endereço pelo CEP…', 'loading');
            cepField.setAttribute('aria-busy', 'true');

            try {
                const response = await fetch(`https://viacep.com.br/ws/${encodeURIComponent(cep)}/json/`, {
                    signal: controller.signal,
                    headers: {'Accept': 'application/json'},
                });
                if (!response.ok) throw new Error('Não foi possível consultar este CEP.');

                const data = await response.json();
                if (data.erro) throw new Error('CEP não encontrado. Confira os números digitados.');

                if (street && data.logradouro) street.value = data.logradouro;
                if (neighborhood && data.bairro) neighborhood.value = data.bairro;
                if (city && data.localidade) city.value = data.localidade;
                if (state && data.uf) state.value = String(data.uf).toUpperCase();
                if (ibge) ibge.value = digits(data.ibge).slice(0, 7);

                lastLookup = cep;
                setStatus('Endereço encontrado. Complete o número e revise os dados.', 'success');
                if (number && !number.value.trim()) number.focus();
            } catch (error) {
                if (error?.name === 'AbortError') return;
                lastLookup = '';
                setStatus(error instanceof Error ? error.message : 'Não foi possível consultar o CEP agora.', 'error');
            } finally {
                cepField.removeAttribute('aria-busy');
            }
        };

        cepField.addEventListener('input', () => {
            cepField.value = formatCep(cepField.value);
            if (digits(cepField.value).length === 8) lookup();
            else {
                lastLookup = '';
                setStatus('Digite os 8 números do CEP para preencher o endereço.');
            }
        });
        cepField.addEventListener('blur', lookup);
        cepField.value = formatCep(cepField.value);
    });
})();
