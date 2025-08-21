FROM node:18-alpine

WORKDIR /app

# Install dependencies
COPY package.json package-lock.json* ./
RUN npm install

# Copy the rest of the application
COPY . .

# Expose the port the app will run on
EXPOSE 5173

# Command to run the app
#CMD ["npm", "run", "dev", "--", "--host", "0.0.0.0"]
CMD ["tail", "-f", "/dev/null"]
