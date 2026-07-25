(() => {
    'use strict';

    const config = window.BCSTestAutofill053 || {};
    if (!config.enabled) return;

    const firstNamesFemale = ['Anna', 'Katarzyna', 'Monika', 'Magdalena', 'Joanna', 'Agnieszka'];
    const firstNamesMale = ['Piotr', 'Michał', 'Tomasz', 'Marcin', 'Paweł', 'Łukasz'];
    const childNamesFemale = ['Zuzanna', 'Maja', 'Julia', 'Natalia', 'Oliwia', 'Amelia'];
    const childNamesMale = ['Jakub', 'Kacper', 'Mateusz', 'Szymon', 'Filip', 'Antoni'];
    const surnames = [
        { male: 'Kowalski', female: 'Kowalska' },
        { male: 'Nowak', female: 'Nowak' },
        { male: 'Wiśniewski', female: 'Wiśniewska' },
        { male: 'Wójcik', female: 'Wójcik' },
        { male: 'Kamiński', female: 'Kamińska' },
        { male: 'Lewandowski', female: 'Lewandowska' },
    ];
    const cities = [
        { city: 'Pelplin', postal: '83-130', street: 'Mickiewicza' },
        { city: 'Tczew', postal: '83-110', street: 'Bałdowska' },
        { city: 'Starogard Gdański', postal: '83-200', street: 'Hallera' },
        { city: 'Gdańsk', postal: '80-180', street: 'Lawendowe Wzgórze' },
        { city: 'Malbork', postal: '82-200', street: 'Słowackiego' },
    ];
    const clubs = ['brak', 'UKS Basket Test', 'MKS Testowy', 'Akademia Koszykówki Test'];

    const randomItem = (items) => items[Math.floor(Math.random() * items.length)];
    const randomInt = (min, max) => Math.floor(Math.random() * (max - min + 1)) + min;
    const pad = (value) => String(value).padStart(2, '0');

    const dispatch = (element) => {
        element.dispatchEvent(new Event('input', { bubbles: true }));
        element.dispatchEvent(new Event('change', { bubbles: true }));
    };

    const field = (form, name) => {
        const element = form.elements.namedItem(name);
        return element instanceof HTMLElement ? element : null;
    };

    const setValue = (form, name, value) => {
        const element = field(form, name);
        if (!element) return;
        element.value = String(value ?? '');
        dispatch(element);
    };

    const setChecked = (form, name, checked) => {
        const element = field(form, name);
        if (!(element instanceof HTMLInputElement)) return;
        element.checked = Boolean(checked);
        dispatch(element);
    };

    const dateToInput = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;

    const randomBirthDate = () => {
        const start = new Date(2009, 0, 1).getTime();
        const end = new Date(2016, 11, 31).getTime();
        return new Date(randomInt(start, end));
    };

    const generatePesel = (date, female) => {
        const year = date.getFullYear();
        const yearPart = pad(year % 100);
        let month = date.getMonth() + 1;
        if (year >= 2000 && year <= 2099) month += 20;
        const serialA = randomInt(0, 9);
        const serialB = randomInt(0, 9);
        const serialC = randomInt(0, 9);
        const genderDigit = female
            ? randomItem([0, 2, 4, 6, 8])
            : randomItem([1, 3, 5, 7, 9]);
        const firstTen = `${yearPart}${pad(month)}${pad(date.getDate())}${serialA}${serialB}${serialC}${genderDigit}`;
        const weights = [1, 3, 7, 9, 1, 3, 7, 9, 1, 3];
        const sum = firstTen.split('').reduce((total, digit, index) => total + Number(digit) * weights[index], 0);
        const checksum = (10 - (sum % 10)) % 10;
        return `${firstTen}${checksum}`;
    };

    const shirtSizeForHeight = (height) => {
        if (height < 140) return '134-140';
        if (height < 146) return '140-146';
        if (height < 152) return '146-152';
        if (height < 158) return '152-158';
        if (height < 164) return '158-164';
        if (height < 170) return 'S-164-170';
        if (height < 176) return 'M-170-176';
        if (height < 182) return 'L-176-182';
        if (height < 188) return 'XL-182-188';
        return '2XL-188-194';
    };

    const ensureStyles = () => {
        if (document.getElementById('bcs-test-autofill-053-style')) return;
        const style = document.createElement('style');
        style.id = 'bcs-test-autofill-053-style';
        style.textContent = `
            .bcs-test-autofill-053{display:flex;align-items:center;justify-content:space-between;gap:18px;margin:0 0 20px;padding:16px 18px;border:1px solid #fbbf24;border-radius:14px;background:#fffbeb;color:#78350f}
            .bcs-test-autofill-053-copy{display:flex;align-items:flex-start;gap:12px}
            .bcs-test-autofill-053-icon{display:grid;place-items:center;flex:0 0 auto;width:38px;height:38px;border-radius:11px;background:#f59e0b;color:#fff;font-size:20px}
            .bcs-test-autofill-053 strong,.bcs-test-autofill-053 small{display:block}
            .bcs-test-autofill-053 strong{margin:0 0 3px;font-size:15px}
            .bcs-test-autofill-053 small{color:#92400e;line-height:1.4}
            .bcs-test-autofill-053-button{flex:0 0 auto;border:0;border-radius:10px;padding:12px 16px;background:#f97316;color:#fff;font:inherit;font-weight:800;cursor:pointer;box-shadow:0 6px 14px rgba(249,115,22,.18)}
            .bcs-test-autofill-053-button:hover{background:#c2410c}
            .bcs-test-autofill-053-status{display:block;margin-top:7px;font-weight:700;color:#166534}
            .bcs-test-autofill-053-status.is-warning{color:#b45309}
            @media(max-width:700px){.bcs-test-autofill-053{align-items:stretch;flex-direction:column}.bcs-test-autofill-053-button{width:100%}}
        `;
        document.head.appendChild(style);
    };

    const fillForm = (form, status) => {
        const emailElement = field(form, 'parent_email');
        const phoneElement = field(form, 'parent_phone');
        const preservedEmail = emailElement ? emailElement.value : '';
        const preservedPhone = phoneElement ? phoneElement.value : '';

        const primaryFemale = Math.random() >= 0.5;
        const childFemale = Math.random() >= 0.5;
        const surname = randomItem(surnames);
        const parentFirst = randomItem(primaryFemale ? firstNamesFemale : firstNamesMale);
        const secondParentFirst = randomItem(primaryFemale ? firstNamesMale : firstNamesFemale);
        const parentLast = primaryFemale ? surname.female : surname.male;
        const secondParentLast = primaryFemale ? surname.male : surname.female;
        const childFirst = randomItem(childFemale ? childNamesFemale : childNamesMale);
        const childLast = childFemale ? surname.female : surname.male;
        const address = randomItem(cities);
        const birthDate = randomBirthDate();
        const height = randomInt(138, 190);
        const weight = Math.max(30, Math.round((height - 100) * 0.86 * 10) / 10);
        const house = `${randomInt(1, 85)}${Math.random() > 0.65 ? `/${randomInt(1, 12)}` : ''}`;
        const currentYear = new Date().getFullYear();
        const contactPhone = preservedPhone.trim() || '[uzupełnij numer telefonu]';

        setValue(form, 'parent_first_name', parentFirst);
        setValue(form, 'parent_last_name', parentLast);
        setValue(form, 'parent_phone_alt', '');
        setValue(form, 'parents_names', `${parentFirst} ${parentLast}, ${secondParentFirst} ${secondParentLast}`);
        setValue(form, 'parent_postal_code', address.postal);
        setValue(form, 'parent_city', address.city);
        setValue(form, 'parent_street', `ul. ${address.street}`);
        setValue(form, 'parent_house_number', house);
        setValue(form, 'parent_address', `${address.postal} ${address.city}, ul. ${address.street} ${house}`);

        setValue(form, 'child_first_name', childFirst);
        setValue(form, 'child_last_name', childLast);
        setValue(form, 'child_birth_date', dateToInput(birthDate));
        setValue(form, 'child_pesel', generatePesel(birthDate, childFemale));
        setValue(form, 'child_height', height);
        setValue(form, 'child_weight', weight.toFixed(1));
        setValue(form, 'shirt_size', shirtSizeForHeight(height));
        setValue(form, 'child_club', randomItem(clubs));
        setValue(form, 'child_address', '');
        setValue(form, 'special_educational_needs', 'brak');
        setValue(form, 'medical_notes', 'brak');
        setValue(form, 'dietary_notes', 'brak');
        setValue(form, 'vaccination_tetanus', String(currentYear - randomInt(1, 4)));
        setValue(form, 'vaccination_diphtheria', String(currentYear - randomInt(1, 4)));
        setValue(form, 'vaccination_other', 'zgodnie z kalendarzem szczepień');

        setValue(form, 'stay_contact', `${parentFirst} ${parentLast} — ${contactPhone}`);
        setValue(form, 'authorized_pickup', `${secondParentFirst} ${secondParentLast}`);
        setValue(form, 'camp_notes', 'Dane testowe wygenerowane automatycznie w trybie testowym.');

        setChecked(form, 'invoice_requested', false);
        setValue(form, 'invoice_buyer_name', '');
        setValue(form, 'invoice_street', '');
        setValue(form, 'invoice_postal_code', '');
        setValue(form, 'invoice_city', '');
        setValue(form, 'invoice_nip', '');
        setValue(form, 'invoice_notes', '');

        form.querySelectorAll('input[type="checkbox"][required]').forEach((checkbox) => {
            checkbox.checked = true;
            dispatch(checkbox);
        });

        if (emailElement) emailElement.value = preservedEmail;
        if (phoneElement) phoneElement.value = preservedPhone;

        const missing = [];
        if (!preservedEmail.trim()) missing.push('e-mail');
        if (!preservedPhone.trim()) missing.push('telefon');
        status.textContent = missing.length
            ? `${config.success || 'Formularz wypełniono.'} ${config.missingPrefix || 'Uzupełnij ręcznie: '}${missing.join(' i ')}.`
            : (config.success || 'Formularz został wypełniony losowymi danymi.');
        status.classList.toggle('is-warning', missing.length > 0);

        if (!preservedEmail.trim() && emailElement) {
            emailElement.focus();
        } else if (!preservedPhone.trim() && phoneElement) {
            phoneElement.focus();
        }
    };

    const attach = (form) => {
        if (!(form instanceof HTMLFormElement) || form.dataset.bcsTestAutofill053 === '1') return;
        form.dataset.bcsTestAutofill053 = '1';
        ensureStyles();

        const panel = document.createElement('div');
        panel.className = 'bcs-test-autofill-053';
        panel.innerHTML = `
            <div class="bcs-test-autofill-053-copy">
                <span class="bcs-test-autofill-053-icon" aria-hidden="true">⚄</span>
                <div>
                    <strong>${config.heading || 'Tryb testowy'}</strong>
                    <small>${config.description || 'Wypełnij formularz przykładowymi danymi.'}</small>
                    <span class="bcs-test-autofill-053-status" role="status" aria-live="polite"></span>
                </div>
            </div>
            <button type="button" class="bcs-test-autofill-053-button">${config.buttonLabel || 'Wypełnij losowymi danymi'}</button>
        `;

        const firstSection = form.querySelector('.bcs-form-section');
        form.insertBefore(panel, firstSection || form.firstChild);
        const button = panel.querySelector('.bcs-test-autofill-053-button');
        const status = panel.querySelector('.bcs-test-autofill-053-status');
        button.addEventListener('click', () => fillForm(form, status));
    };

    const init = () => document.querySelectorAll('form.bcs-camp-form').forEach(attach);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }

    const observer = new MutationObserver(init);
    observer.observe(document.documentElement, { childList: true, subtree: true });
})();
