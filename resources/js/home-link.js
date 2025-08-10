// included in LinkEnhancerModule.php headContent and wrapped in iife syntax
// theme string
// config object { '*': string, 'themename': string }
//<script>
//((theme, config) => {
    if (config && typeof config === 'object') { // nesting because iife syntax is added by php and so return leads to SyntaxError!
        let theme_level1 = theme.split('_')[0]; // special with colors where we have also palettes
        let stylerules = config[theme] ?? config[theme_level1] ?? config['*'] ?? null;
        if (stylerules) {
            let style = document.createElement('style');
            style.textContent = stylerules;
            document.head.appendChild(style);
        }
    }
// }) (theme, config)</script >