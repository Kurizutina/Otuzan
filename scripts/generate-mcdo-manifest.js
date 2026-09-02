const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '../public/images/Mcdo (Mega Meal)');
const pricesRoot = path.join(root, '.Prices');
const outputPath = path.join(root, 'menu-manifest.json');

const categoryPriceFiles = {
  Burgers: 'Mcdo Burgers Price list.txt',
  'Chicken Only Meals': 'Chicken Only Meals Prices.txt',
  Desserts: 'Desserts & Drinks Prices.txt',
  'Group Meals': 'Mcdo Group Meals Menu Price list.txt',
  'Happy Meal': 'Mcdo Happy Meals Menu Price list.txt',
  'McCaffe Coffees': 'McCafe Coffee Menu Price list.txt',
  'McDo Fries': 'Fries Prices.txt',
  'McSpaghetti Menu': 'McSpaghetti Menu Prices.txt',
  'Rice Bowls': 'Rice Bowls Prices.txt',
  Sauces: 'Sauce Prices.txt',
  'Special Meals': 'Mcdo Special Meals Price list.txt',
  'Sulit Busog Meals': 'Mcdo Sulit Busog Meals Menu Price list.txt'
};

const normalize = (value) => value
  .normalize('NFKD')
  .replace(/[\u0300-\u036f]/g, '')
  .toLowerCase()
  .replace(/\(1\)/g, '')
  .replace(/&/g, ' and ')
  .replace(/\bw\b/g, ' with ')
  .replace(/\bpc\b/g, ' piece ')
  .replace(/\bp\b/g, ' piece ')
  .replace(/mcdo image \d+/g, '')
  .replace(/[^a-z0-9]+/g, ' ')
  .replace(/\s+/g, ' ')
  .trim();

const distance = (left, right) => {
  const rows = Array.from({ length: right.length + 1 }, (_, index) => index);
  for (let i = 1; i <= left.length; i += 1) {
    let previous = rows[0];
    rows[0] = i;
    for (let j = 1; j <= right.length; j += 1) {
      const saved = rows[j];
      rows[j] = Math.min(
        rows[j] + 1,
        rows[j - 1] + 1,
        previous + (left[i - 1] === right[j - 1] ? 0 : 1)
      );
      previous = saved;
    }
  }
  return rows[right.length];
};

const similarity = (left, right) => {
  if (!left || !right) return 0;
  if (left === right) return 1;
  return 1 - distance(left, right) / Math.max(left.length, right.length);
};

const parsePrices = (filePath) => {
  const lines = fs.readFileSync(filePath, 'utf8')
    .replace(/^\uFEFF/, '')
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter(Boolean);
  const entries = [];

  lines.forEach((line, index) => {
    const inline = line.match(/^(.*?)\s+-\s+₱\s*([\d,.]+)/);
    if (inline) {
      entries.push({ name: inline[1].trim(), price: Number(inline[2].replace(/,/g, '')) });
      return;
    }

    const nextPrice = lines[index + 1]?.match(/^₱\s*([\d,.]+)/);
    if (nextPrice && !line.startsWith('₱')) {
      entries.push({ name: line, price: Number(nextPrice[1].replace(/,/g, '')) });
    }
  });

  return entries.map((entry) => ({ ...entry, normalized: normalize(entry.name) }));
};

const imageExtensions = new Set(['.webp', '.jpg', '.jpeg', '.png']);
const manifest = [];

Object.entries(categoryPriceFiles).forEach(([category, priceFile]) => {
  const categoryPath = path.join(root, category);
  const files = fs.readdirSync(categoryPath)
    .filter((file) => imageExtensions.has(path.extname(file).toLowerCase()))
    .filter((file) => !/\(1\)\.[^.]+$/i.test(file))
    .sort((left, right) => left.localeCompare(right));
  const prices = parsePrices(path.join(pricesRoot, priceFile));
  const unnamedProducts = [];
  const matchedPriceIndexes = new Set();

  const categoryItems = files.map((file) => {
    const rawName = path.basename(file, path.extname(file)).replace(/-/g, ' ').replace(/\s+/g, ' ').trim();
    const normalizedName = normalize(rawName);

    if (!normalizedName || /drinks mcdo image/i.test(rawName)) {
      const item = { category, name: rawName, file };
      unnamedProducts.push(item);
      return item;
    }

    let bestIndex = 0;
    let bestScore = -1;
    prices.forEach((entry, index) => {
      const score = similarity(normalizedName, entry.normalized);
      if (score > bestScore) {
        bestIndex = index;
        bestScore = score;
      }
    });
    matchedPriceIndexes.add(bestIndex);
    return { category, name: rawName, file, price: prices[bestIndex].price, priceName: prices[bestIndex].name };
  });

  const remainingPrices = prices.filter((_, index) => !matchedPriceIndexes.has(index));
  unnamedProducts.forEach((item, index) => {
    const matched = remainingPrices[index];
    if (matched) {
      item.name = matched.name;
      item.price = matched.price;
      item.priceName = matched.name;
    }
  });

  categoryItems.forEach((item) => manifest.push({
    category: item.category,
    name: item.name,
    price: item.price,
    image: `/images/Mcdo (Mega Meal)/${category}/${item.file}`
  }));
});

fs.writeFileSync(outputPath, `${JSON.stringify(manifest, null, 2)}\n`, 'utf8');
console.log(`Generated ${manifest.length} priced McDonald’s products.`);
