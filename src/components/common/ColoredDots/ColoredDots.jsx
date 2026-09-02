import React from 'react';

const ColoredDots = () => {
  const colors = [
    '#F9C12F',
    '#DA1C5C',
    '#F15A29',
    '#FF5DD4',
    '#FF9846'
  ];

  return (
    <div className="color-dots">
      {colors.map((color, index) => (
        <div
          key={index}
          className="dot"
          style={{ background: color }}
        ></div>
      ))}
    </div>
  );
};

export default ColoredDots;