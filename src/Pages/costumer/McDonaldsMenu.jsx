import React, { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import './JollibeeMenu.css';

const formatPrice = (price) => new Intl.NumberFormat('en-PH', {
  style: 'currency',
  currency: 'PHP',
  minimumFractionDigits: 0,
  maximumFractionDigits: 2
}).format(price);

const McDonaldsMenu = () => {
  const navigate = useNavigate();
  const [products, setProducts] = useState([]);
  const [cart, setCart] = useState([]);
  const [customName, setCustomName] = useState('');
  const [customQuantity, setCustomQuantity] = useState(1);
  const [showCustomItem, setShowCustomItem] = useState(false);
  const [notice, setNotice] = useState('');
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    fetch('/images/Mcdo%20(Mega%20Meal)/menu-manifest.json')
      .then((response) => {
        if (!response.ok) throw new Error('Unable to load the McDonald’s menu.');
        return response.json();
      })
      .then((items) => setProducts(items.map((item, index) => ({
        ...item,
        id: `mcdo-${index}`,
        name: item.name.replace(/\s+/g, ' ').trim(),
        image: encodeURI(item.image)
      }))))
      .catch(() => setNotice('The menu could not be loaded. Please try again.'))
      .finally(() => setIsLoading(false));
  }, []);

  const categories = useMemo(() => products.reduce((groups, product) => {
    const existing = groups.find((group) => group.category === product.category);
    if (existing) existing.products.push(product);
    else groups.push({ category: product.category, products: [product] });
    return groups;
  }, []), [products]);

  const itemCount = useMemo(
    () => cart.reduce((total, item) => total + item.quantity, 0),
    [cart]
  );

  const cartSubtotal = useMemo(
    () => cart.reduce((total, item) => total + (item.price || 0) * item.quantity, 0),
    [cart]
  );

  const showNotice = (message) => {
    setNotice(message);
    window.setTimeout(() => setNotice(''), 2600);
  };

  const addToCart = (product, quantity = 1) => {
    setCart((current) => {
      const existing = current.find((item) => item.id === product.id);
      return existing
        ? current.map((item) => item.id === product.id
          ? { ...item, quantity: item.quantity + quantity }
          : item)
        : [...current, { ...product, quantity }];
    });
    showNotice(`${product.name} added to cart.`);
  };

  const updateQuantity = (id, amount) => {
    setCart((current) => current
      .map((item) => item.id === id ? { ...item, quantity: item.quantity + amount } : item)
      .filter((item) => item.quantity > 0));
  };

  const placeProductOrder = (product) => {
    console.log('McDonald’s product order placed:', { ...product, quantity: 1 });
    showNotice(`Order placed for ${product.name}.`);
  };

  const addCustomItem = (event) => {
    event.preventDefault();
    const item = {
      id: `custom-${Date.now()}`,
      name: customName.trim(),
      image: null,
      custom: true
    };
    addToCart(item, customQuantity);
    setCustomName('');
    setCustomQuantity(1);
    setShowCustomItem(false);
  };

  const checkoutCart = () => {
    if (!cart.length) return;
    console.log('McDonald’s cart order placed:', cart);
    showNotice(`Order placed with ${itemCount} item${itemCount === 1 ? '' : 's'}.`);
    setCart([]);
  };

  return (
    <main className="jollibee-page mcdo-page">
      <header className="jollibee-page-header mcdo-page-header">
        <button type="button" className="jollibee-back" onClick={() => navigate('/home')}>
          <i className="fa-solid fa-arrow-left" aria-hidden="true" /> Back
        </button>
        <div>
          <span>Food Delivery</span>
          <h1>McDonald’s Menu</h1>
        </div>
        <button type="button" className="jollibee-cart-link" onClick={() => document.getElementById('mcdo-cart')?.scrollIntoView({ behavior: 'smooth' })}>
          <i className="fa-solid fa-cart-shopping" aria-hidden="true" />
          Cart <strong>{itemCount}</strong>
        </button>
      </header>

      {notice && <div className="jollibee-notice" role="status">{notice}</div>}

      <div className="jollibee-layout">
        <div className="jollibee-menu-content">
          <section className="jollibee-intro">
            <div>
              <p className="jollibee-eyebrow mcdo-eyebrow">Choose your favorites</p>
              <h2>What are you craving today?</h2>
              <p>Browse the complete McDonald’s menu by category or request something not listed.</p>
            </div>
            <button type="button" onClick={() => setShowCustomItem((current) => !current)}>
              <i className="fa-solid fa-plus" aria-hidden="true" /> Add an item not on the menu
            </button>
          </section>

          {showCustomItem && (
            <form className="jollibee-custom-item" onSubmit={addCustomItem}>
              <div><h3>Request another item</h3><p>Enter its name and quantity, then add it to your cart.</p></div>
              <label>
                <span>Item name</span>
                <input autoFocus required value={customName} onChange={(event) => setCustomName(event.target.value)} placeholder="Enter custom item" />
              </label>
              <label className="custom-quantity-field">
                <span>Quantity</span>
                <input required type="number" min="1" max="99" value={customQuantity} onChange={(event) => setCustomQuantity(Math.max(1, Number(event.target.value)))} />
              </label>
              <button type="submit">Add to Cart</button>
            </form>
          )}

          {isLoading && <div className="restaurant-menu-loading"><i className="fa-solid fa-spinner fa-spin" /> Loading menu…</div>}

          {categories.map(({ category, products: categoryProducts }) => (
            <section className="jollibee-category" key={category}>
              <div className="jollibee-category-heading">
                <h2>{category}</h2><span>{categoryProducts.length} items</span>
              </div>
              <div className="jollibee-product-grid">
                {categoryProducts.map((product) => (
                  <article className="jollibee-product-card" key={product.id}>
                    <div className="jollibee-product-image"><img src={product.image} alt={product.name} loading="lazy" /></div>
                    <div className="jollibee-product-body">
                      <h3>{product.name}</h3>
                      <strong className="restaurant-product-price">{formatPrice(product.price)}</strong>
                      <div className="jollibee-product-actions">
                        <button type="button" className="product-cart-button" onClick={() => addToCart(product)}>
                          <i className="fa-solid fa-cart-plus" aria-hidden="true" /> Add to Cart
                        </button>
                        <button type="button" className="product-order-button" onClick={() => placeProductOrder(product)}>Place Order</button>
                      </div>
                    </div>
                  </article>
                ))}
              </div>
            </section>
          ))}
        </div>

        <aside className="jollibee-cart-panel" id="mcdo-cart">
          <div className="jollibee-cart-heading">
            <div><span>Your order</span><h2>Shopping Cart</h2></div><strong>{itemCount}</strong>
          </div>
          {!cart.length ? (
            <div className="jollibee-empty-cart">
              <i className="fa-solid fa-basket-shopping" aria-hidden="true" />
              <p>Your cart is empty.</p><span>Add something delicious from the menu.</span>
            </div>
          ) : (
            <div className="jollibee-cart-items">
              {cart.map((item) => (
                <div className="jollibee-cart-item" key={item.id}>
                  <div>
                    <strong>{item.name}</strong>
                    {item.custom
                      ? <span>Price to be confirmed</span>
                      : <span>{formatPrice(item.price * item.quantity)}</span>}
                  </div>
                  <div className="jollibee-cart-quantity">
                    <button type="button" onClick={() => updateQuantity(item.id, -1)} aria-label={`Decrease ${item.name} quantity`}>−</button>
                    <output>{item.quantity}</output>
                    <button type="button" onClick={() => updateQuantity(item.id, 1)} aria-label={`Increase ${item.name} quantity`}>＋</button>
                  </div>
                </div>
              ))}
            </div>
          )}
          {cart.length > 0 && (
            <div className="restaurant-cart-total">
              <span>Menu subtotal</span>
              <strong>{formatPrice(cartSubtotal)}</strong>
            </div>
          )}
          <button type="button" className="jollibee-checkout" disabled={!cart.length} onClick={checkoutCart}>Place Order {itemCount > 0 && `(${itemCount})`}</button>
        </aside>
      </div>
    </main>
  );
};

export default McDonaldsMenu;
