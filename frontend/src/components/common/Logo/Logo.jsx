import React, { useState } from 'react';

const Logo = ({
  imageSrc = '/images/otu-zan-logo.jpg',
  altText = 'Otu-Zan Delivery Logo'
}) => {
  const [imageError, setImageError] = useState(false);

  return (
    <div className="logo-container">

      <div className="logo-placeholder">

        {!imageError ? (
          <img
            src={imageSrc}
            alt={altText}
            className="logo-image"
            onError={() => setImageError(true)}
          />
        ) : (
          <i className="fa-solid fa-motorcycle fallback-icon"></i>
        )}

      </div>

      <div className="logo-text">
        Otu-Zan
      </div>

      <div className="logo-sub">
        Delivery · Fast & Reliable
      </div>

    </div>
  );
};

export default Logo;