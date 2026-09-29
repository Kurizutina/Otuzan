import React from 'react';
import './BrandCard.css';

const BrandCard = ({ brand, onSelect }) => {
  return (
    <button
      className={`brand-card ${brand.type}`}
      type="button"
      onClick={() => onSelect?.(brand)}
      aria-label={`Select ${brand.name}`}
    >

      <div className="brand-image-container">

        {brand.image ? (
          <img
            src={brand.image}
            alt={brand.name}
            className={`brand-image ${brand.logoZoom ? `brand-image-zoom-${brand.logoZoom}` : ''}`}
          />
        ) : (
          <div className="brand-image-placeholder">
            <i className="fa-solid fa-store"></i>
          </div>
        )}

      </div>

      <div className="brand-name">
        {brand.name}
      </div>

    </button>
  );
};

export default BrandCard;
