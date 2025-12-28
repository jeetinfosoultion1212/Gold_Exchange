# Font Usage Guide - Mormukut Gold Management System

## 🎨 **Current Font Configuration**

### **✅ SINGLE FONT: Poppins**

The entire project now uses **Poppins** as the primary font family for all UI elements, ensuring consistency and a modern, professional appearance.

---

## 📦 **Font Configuration**

### **Google Fonts Import**
```html
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
```

### **Tailwind CSS Configuration**
```javascript
fontFamily: {
    'sans': ['Poppins', '-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'sans-serif'],
    'poppins': ['Poppins', '-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'sans-serif'],
}
```

---

## 🎯 **Font Weight Usage**

| Weight | Usage | Examples |
|--------|-------|----------|
| **300** (Light) | Subtle text, secondary information | Footer text, subtle labels |
| **400** (Regular) | Body text, form inputs | Main content, input fields |
| **500** (Medium) | Form labels, emphasized text | Field labels, party names |
| **600** (Semi-Bold) | Buttons, navigation | Action buttons, menu items |
| **700** (Bold) | Headers, titles | Page titles, section headers |
| **800** (Extra-Bold) | Statistics, metrics | Dashboard numbers, key values |
| **900** (Black) | Special emphasis | Company logo, critical alerts |

---

## 📍 **Font Application Across Pages**

### **1. Book Gold Page (`book_gold.php`)**
- **Page Title**: Poppins 700 (Bold)
- **Form Labels**: Poppins 500 (Medium)
- **Input Fields**: Poppins 400 (Regular)
- **Buttons**: Poppins 600 (Semi-Bold)
- **Receipt ID**: Poppins 600 (Semi-Bold)
- **Party List**: Poppins 400-500
- **Modal Titles**: Poppins 700 (Bold)
- **Modal Content**: Poppins 400 (Regular)
- **Statistics Cards**: Poppins 600-800

### **2. Sell Gold Page (`sell_gold.php`)**
- Same font weights as Book Gold page
- Consistent styling across all elements

### **3. Purchase Gold Page (`purchase_gold.php`)**
- Same font weights as Book Gold page
- Purple theme with Poppins typography

### **4. Payment Pages**
- **Payment Receipt (`payment_receipt.php`)**: Green theme with Poppins
- **Payment Send (`payment_send.php`)**: Red theme with Poppins
- Consistent font weights across all elements

### **5. Navigation (Header & Sidebar)**
- **Navigation Items**: Poppins 600 (Semi-Bold)
- **Company Name**: Poppins 700 (Bold)
- **User Name**: Poppins 600 (Semi-Bold)

### **6. All Modals (SweetAlert2)**
- **Modal Titles**: Poppins 700 (Bold)
- **Modal Body**: Poppins 400 (Regular)
- **Button Labels**: Poppins 600 (Semi-Bold)

---

## 🎨 **CSS Implementation**

### **Global Styles (components/layout.php)**
```css
/* Professional party list styling */
.party-item {
    font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    font-weight: 400;
    letter-spacing: -0.01em;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}

/* Professional input styling */
input, select, textarea {
    font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    font-weight: 400;
    letter-spacing: -0.01em;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}

/* Professional button styling */
button {
    font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    font-weight: 600;
    letter-spacing: -0.01em;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}

/* Professional body styling */
body {
    font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    font-weight: 400;
    line-height: 1.5;
    letter-spacing: -0.01em;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}

/* Headers and titles */
h1, h2, h3, h4, h5, h6 {
    font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    font-weight: 700;
    letter-spacing: -0.02em;
}

/* Navigation and menu items */
nav, nav a, nav button {
    font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    font-weight: 600;
}

/* Labels */
label {
    font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    font-weight: 500;
}

/* Code and monospace elements */
code, pre, .font-mono {
    font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    font-weight: 600;
}
```

---

## ✅ **Benefits of Poppins-Only Approach**

1. **Consistency**: Single font family across all pages and components
2. **Modern Look**: Poppins provides a clean, contemporary appearance
3. **Readability**: Excellent legibility across all weights and sizes
4. **Professional**: Suitable for business applications
5. **Performance**: Single font family reduces loading time
6. **Maintenance**: Easier to maintain and update typography

---

## 🔧 **Font Fallbacks**

The font stack includes robust fallbacks:
```css
font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
```

- **Primary**: Poppins (Google Fonts)
- **Fallback 1**: -apple-system (macOS/iOS system font)
- **Fallback 2**: BlinkMacSystemFont (macOS/iOS alternative)
- **Fallback 3**: Segoe UI (Windows system font)
- **Fallback 4**: sans-serif (Generic sans-serif)

---

## 📝 **Implementation Notes**

- All font weights (300-900) are loaded for maximum flexibility
- Font smoothing is enabled for better rendering
- Letter spacing is optimized for readability
- Consistent implementation across all pages and components
- Modal dialogs use inline styles for Poppins consistency

---

## 🎯 **Summary**

**Current Font Usage:**
- **Single Font**: Poppins
- **Weights Used**: 300, 400, 500, 600, 700, 800, 900
- **Coverage**: All pages, forms, modals, navigation, and components
- **Fallbacks**: Robust system font fallbacks
- **Styling**: Professional, modern, and consistent

The project now uses **Poppins exclusively** for a unified, professional appearance across all interfaces! 🎨✨