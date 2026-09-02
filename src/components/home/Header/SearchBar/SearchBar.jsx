import React, { useState } from 'react';
import './SearchBar.css';

const SearchBar = () => {
  const [searchText, setSearchText] = useState('');

  const handleSubmit = (e) => {
    e.preventDefault();

    // Search functionality will be added later
    console.log('Searching for:', searchText);
  };

  return (
    <form className="home-search" onSubmit={handleSubmit}>
    <i className="fas fa-search search-icon"></i>


      <input
        type="text"
        placeholder="Search for food, items, brands..."
        value={searchText}
        onChange={(e) => setSearchText(e.target.value)}
      />

      {searchText && (
        <button
          type="button"
          className="clear-search"
          onClick={() => setSearchText('')}
        >
          <i className="fa-solid fa-xmark"></i>
        </button>
      )}

    </form>
  );
};

export default SearchBar;