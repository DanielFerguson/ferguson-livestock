export const business = {
  name: "Ferguson Livestock",
  siteUrl: "https://www.fergusonlivestock.com.au",
  owners: ["Daniel Ferguson", "Tahlia Ferguson"],
  email: "ferguson.livestock.mg@outlook.com",
  phone: {
    international: "+61427458706",
    display: "0427 458 706",
  },
  location: {
    locality: "Snake Valley",
    region: "VIC",
    postalCode: "3351",
    country: "AU",
    nearbyCity: "Ballarat",
    proximity: "about 30 minutes from Ballarat",
  },
  delivery: {
    feeProductId: "delivery-fee",
    areaName: "Ballarat region",
    description: "Flat-fee personal delivery across the Ballarat region.",
  },
  pickup: {
    label: "Free farm pickup",
    description: "Farm pickup by arrangement in Snake Valley.",
  },
  social: {
    facebook: "https://www.facebook.com/FergusonLivestockMG",
  },
} as const;

export type Business = typeof business;
