const menuFiles = {
  Chickenjoy: [
    '1pc Chickenjoy w Mashed Potato & Drink.jpg',
    'C1 - 1p Chickenjoy.jpg',
    'C2 - 2pc Chickenjoy w Drink.jpg',
    'C3 - 1 pc Chickenjoy w Jolly Spaghetti w Drink.jpg',
    'C4 - 1pc. Chickenjoy w Fries & Drink.jpg',
    'C8- 1pc Chickenjoy w Burger Steak & Drink.jpg'
  ],
  Burgers: [
    'Y1 - Yumberger.jpg',
    'Y2 - Original Cheesy Yumburger w Fries & Drink.jpg',
    'Y3 -Bacon Cheesy Yumburher w Fries & Drink.jpg',
    'Y4 - Champ Jr. w Fries & Drink.jpg',
    'Y5 - Spacial Cheesy Yumburger w Fries & Drink.jpg',
    'Y6 - Aloha Champ Jr. w Fries & Drink.jpg'
  ],
  'Jolly Spaghetti': [
    'S2 - Jolly Spaghetti w Fries & Drink.jpg',
    'S3 - Jolly Spaghetti w Yumburger w Drink.jpg',
    'S4 - Jolly Spaghetti w Cheesy Yumburger w Drink.jpg',
    'S5 - Jolly Spaghetti w 1pc Burger Steak Solo.jpg'
  ],
  'Chicken Fillet & Burger Steak': [
    '1pc Burger Steak w Drink.jpg',
    '1pc Burger Steak w Fries & Drink.jpg',
    '2pc Burger Steak w Drink.jpg',
    'Pepper Cream Chicken Fillet w Drink.jpg',
    'Pepper Cream Chicken Fillet w Fries & Drink.jpg',
    'Pepper Cream w Jolly Spaghetti & Drink.jpg'
  ],
  'Sandwiches & Snacks': [
    '10p Chicken Nuggets.jpg',
    '6p Chicken Nuggets.jpg',
    'Cheesy Classic Jolly Hotdog w Fries & Drink.jpg',
    'Crunchy Chicken Sandwich w Fries & Drink.jpg',
    'Jolly Crispy Fries Bucket.jpg',
    'Fries.jpg',
    'Peach Mango Pie.jpg',
    'Tuna Pie.jpg'
  ]
};

const imageFolders = {
  Chickenjoy: 'ChickenJoy',
  'Jolly Spaghetti': 'Jolly Sphagetti'
};

const cleanName = (fileName) => fileName
  .replace(/\.jpg$/i, '')
  .replace(/^[A-Z]\d+\s*-?\s*/i, '')
  .replace(/^C8-\s*/i, '')
  .replace(/\b1p\b/i, '1pc')
  .replace(/Yumberger/i, 'Yumburger')
  .replace(/Yumburher/i, 'Yumburger')
  .replace(/Spacial/i, 'Special');

export const jollibeeMenu = Object.entries(menuFiles).map(([category, files]) => ({
  category,
  products: files.map((fileName, index) => {
    const name = cleanName(fileName);
    const options = name === 'Fries'
      ? ['Regular', 'Medium', 'Large']
      : ['Tuna Pie', 'Peach Mango Pie'].includes(name)
        ? ['1 piece', '3 pieces']
        : null;

    return {
      id: `${category}-${index}`,
      name,
      image: encodeURI(`/images/Jollibee (MVP)/${imageFolders[category] || category}/${fileName}`),
      options
    };
  })
}));
