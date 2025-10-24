// Türkiye'deki başlıca şehirler
const cities = [
    'Adana', 'Adıyaman', 'Afyonkarahisar', 'Ağrı', 'Aksaray', 'Amasya', 'Ankara', 'Antalya',
    'Ardahan', 'Artvin', 'Aydın', 'Balıkesir', 'Bartın', 'Batman', 'Bayburt', 'Bilecik',
    'Bingöl', 'Bitlis', 'Bolu', 'Burdur', 'Bursa', 'Çanakkale', 'Çankırı', 'Çorum',
    'Denizli', 'Diyarbakır', 'Düzce', 'Edirne', 'Elazığ', 'Erzincan', 'Erzurum', 'Eskişehir',
    'Gaziantep', 'Giresun', 'Gümüşhane', 'Hakkari', 'Hatay', 'Iğdır', 'Isparta', 'Istanbul',
    'Izmir', 'Kahramanmaraş', 'Karabük', 'Karaman', 'Kars', 'Kastamonu', 'Kayseri', 'Kilis',
    'Kırıkkale', 'Kırklareli', 'Kırşehir', 'Kocaeli', 'Konya', 'Kütahya', 'Malatya', 'Manisa',
    'Mardin', 'Mersin', 'Muğla', 'Muş', 'Nevşehir', 'Niğde', 'Ordu', 'Osmaniye',
    'Rize', 'Sakarya', 'Samsun', 'Şanlıurfa', 'Siirt', 'Sinop', 'Şırnak', 'Sivas',
    'Tekirdağ', 'Tokat', 'Trabzon', 'Tunceli', 'Uşak', 'Van', 'Yalova', 'Yozgat', 'Zonguldak'
];

class Autocomplete {
    constructor(inputElement, options = {}) {
        this.input = inputElement;
        this.options = options.data || cities;
        this.minChars = options.minChars || 1;
        this.maxResults = options.maxResults || 10;
        this.container = null;
        this.selectedIndex = -1;
        
        this.init();
    }
    
    init() {
        this.input.setAttribute('autocomplete', 'off');
        this.input.addEventListener('input', this.handleInput.bind(this));
        this.input.addEventListener('keydown', this.handleKeydown.bind(this));
        this.input.addEventListener('blur', () => {
            setTimeout(() => this.hide(), 200);
        });
    }
    
    handleInput(e) {
        const value = e.target.value.trim();
        
        if (value.length < this.minChars) {
            this.hide();
            return;
        }
        
        const matches = this.options.filter(item =>
            item.toLowerCase().indexOf(value.toLowerCase()) !== -1
        ).slice(0, this.maxResults);
        
        if (matches.length > 0) {
            this.show(matches);
        } else {
            this.hide();
        }
    }
    
    handleKeydown(e) {
        if (!this.container) return;
        
        const items = this.container.querySelectorAll('.autocomplete-item');
        
        switch(e.key) {
            case 'ArrowDown':
                e.preventDefault();
                this.selectedIndex = Math.min(this.selectedIndex + 1, items.length - 1);
                this.updateSelection(items);
                break;
            case 'ArrowUp':
                e.preventDefault();
                this.selectedIndex = Math.max(this.selectedIndex - 1, -1);
                this.updateSelection(items);
                break;
            case 'Enter':
                e.preventDefault();
                if (this.selectedIndex >= 0 && items[this.selectedIndex]) {
                    this.input.value = items[this.selectedIndex].textContent;
                    this.hide();
                }
                break;
            case 'Escape':
                this.hide();
                break;
        }
    }
    
    updateSelection(items) {
        items.forEach((item, index) => {
            if (index === this.selectedIndex) {
                item.classList.add('selected');
            } else {
                item.classList.remove('selected');
            }
        });
    }
    
    show(matches) {
        this.hide();
        
        this.container = document.createElement('div');
        this.container.className = 'autocomplete-container';
        
        matches.forEach(match => {
            const item = document.createElement('div');
            item.className = 'autocomplete-item';
            item.textContent = match;
            item.addEventListener('click', () => {
                this.input.value = match;
                this.hide();
            });
            this.container.appendChild(item);
        });
        
        this.input.parentNode.style.position = 'relative';
        this.input.parentNode.appendChild(this.container);
        this.selectedIndex = -1;
    }
    
    hide() {
        if (this.container) {
            this.container.remove();
            this.container = null;
        }
        this.selectedIndex = -1;
    }
}

// Auto-initialize on DOM ready
document.addEventListener('DOMContentLoaded', () => {
    const originInput = document.querySelector('input[name="origin"]');
    const destInput = document.querySelector('input[name="destination"]');
    
    if (originInput) new Autocomplete(originInput);
    if (destInput) new Autocomplete(destInput);
});

